<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Input,Problem};
/** A session is only a bag of identifiers; prices always come from current server records. */
final class Cart
{
    public static function lines(): array
    {
        $lines=[];
        foreach (($_SESSION['cart']??[]) as $key=>$value) {
            if (is_array($value)) { $id=(int)($value['item_id']??0);$variant=(int)($value['variant_id']??0);$qty=(int)($value['quantity']??0); }
            else { $id=(int)$key;$variant=0;$qty=(int)$value; }
            if ($id>0 && $variant>=0 && $qty>0 && $qty<=10) { $lines[$id.':'.$variant]=['item_id'=>$id,'variant_id'=>$variant,'quantity'=>$qty]; }
        }
        return $lines;
    }
    public static function put(array $item, int $variant, int $quantity, Database $db): void
    {
        Input::integer($quantity,1,10);Input::integer($variant);
        if ($item['kind']!=='product') { throw new Problem('Only shop products can be added to the cart.'); }
        $count=(int)$db->value('SELECT COUNT(*) FROM cy_variants WHERE content_id=?',[(int)$item['id']]);
        if (($count && !$variant) || ($variant && !$db->one('SELECT id FROM cy_variants WHERE id=? AND content_id=? AND active=1',[$variant,(int)$item['id']]))) { throw new Problem('Please select an available variant.'); }
        if ($item['product_type']==='digital') { $quantity=1; }
        $lines=self::lines();$key=$item['id'].':'.$variant;
        if (count($lines)>=20 && !isset($lines[$key])) { throw new Problem('Cart limit reached.'); }
        $lines[$key]=['item_id'=>(int)$item['id'],'variant_id'=>$variant,'quantity'=>$quantity];$_SESSION['cart']=$lines;
    }
    public static function remove(int $id, int $variant): void { $lines=self::lines();unset($lines[$id.':'.$variant]);$_SESSION['cart']=$lines; }
}
