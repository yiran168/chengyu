<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Crypto, Input, Problem};

/** Product row is always the inventory mutex, including all its variants and code imports. */
final class Inventory
{
    private Database $db; private Crypto $crypto; private Activity $activity;
    public function __construct(Database $db, Crypto $crypto, Activity $activity)
    { $this->db=$db; $this->crypto=$crypto; $this->activity=$activity; }
    public function variants(int $content, bool $activeOnly=true): array
    { return $this->db->all('SELECT * FROM cy_variants WHERE content_id=?'.($activeOnly?' AND active=1':'').' ORDER BY sort_order,id',[$content]); }
    public function save(int $actor, array $input): int
    {
        $id=Input::integer($input['id']??0); $content=Input::integer($input['content_id']??0,1);
        $sku=Input::required($input['sku']??'',64);
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D',$sku)) { throw new Problem('SKU uses letters, digits, dots, underscores or hyphens.'); }
        return $this->db->transaction(function() use($actor,$input,$id,$content,$sku): int {
            $item=$this->db->one('SELECT * FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
            if (!$item || $item['kind']!=='product' || !in_array($item['product_type'],['physical','code'],true)) { throw new Problem('Variants support physical and code-delivery products.'); }
            $old=$id?$this->db->one('SELECT * FROM cy_variants WHERE id=? AND content_id=?',[$id,$content]):null;
            if ($id && !$old) { throw new Problem('Variant not found.',404); }
            if (!$id && count($this->variants($content,false))>=100) { throw new Problem('Variant limit reached.'); }
            if (!$id && $this->db->one('SELECT id FROM cy_stock_codes WHERE content_id=? AND variant_id=0 AND order_id IS NULL',[$content])) { throw new Problem('Sell or retire unassigned base codes before adding variants.'); }
            if ($this->db->one('SELECT id FROM cy_variants WHERE sku=? AND id<>?',[$sku,$id])) { throw new Problem('SKU is already in use.'); }
            $price=$item['price_currency']==='balance'?Input::cents($input['price']??'0',true):Input::integer($input['price']??0,0,100000000);
            $data=['content_id'=>$content,'sku'=>$sku,'name'=>Input::required($input['name']??'',100),'price_amount'=>$price,
                'inventory'=>$item['product_type']==='code'?(int)($old['inventory']??0):Input::integer($input['inventory']??0,-1,1000000),
                'active'=>empty($input['active'])?0:1,'sort_order'=>Input::integer($input['sort_order']??0,0,9999)];
            if ($id) { $this->db->update('cy_variants',$id,$data); } else { $id=$this->db->insert('cy_variants',$data); }
            $this->synchronize($content); $this->activity->audit($actor,'variant.saved',(string)$id); return $id;
        });
    }
    /** Caller holds product lock. Returns effective price and one unused code, never client prices. */
    public function selection(array $item, int $variantId): array
    {
        $variants=$this->variants((int)$item['id'],false); $variant=null;
        if ($variants || $variantId) {
            foreach ($variants as $candidate) { if ((int)$candidate['id']===$variantId && (int)$candidate['active']) { $variant=$candidate; break; } }
            if (!$variant) { throw new Problem('Select an available product variant.'); }
        }
        $stock=(int)($variant['inventory']??$item['inventory']);
        if ($stock===0) { throw new Problem('Out of stock.'); }
        $code=null;
        if ($item['product_type']==='code') {
            $code=$this->db->one('SELECT * FROM cy_stock_codes WHERE content_id=? AND variant_id=? AND order_id IS NULL ORDER BY id LIMIT 1'.$this->db->lock(),[(int)$item['id'],$variantId]);
            if (!$code) { throw new Problem('Out of stock.'); }
        }
        return ['variant'=>$variant,'price'=>(int)($variant['price_amount']??$item['price_amount']),'code'=>$code];
    }
    public function consume(array $item, array $selection, int $order): void
    {
        $variant=$selection['variant']; $code=$selection['code']; $id=(int)$item['id'];
        if ($code) {
            if ($this->db->execute('UPDATE cy_stock_codes SET order_id=? WHERE id=? AND order_id IS NULL',[$order,(int)$code['id']])!==1) { throw new Problem('Out of stock.'); }
            $this->synchronize($id);
        } else {
            $table=$variant?'cy_variants':'cy_contents'; $target=$variant?(int)$variant['id']:$id; $stock=(int)($variant['inventory']??$item['inventory']);
            if ($stock>0 && $this->db->execute('UPDATE '.$table.' SET inventory=inventory-1 WHERE id=? AND inventory>0',[$target])!==1) { throw new Problem('Out of stock.'); }
            if ($variant) { $this->synchronize($id); }
        }
    }
    public function restore(int $content, int $variant): void
    {
        $this->db->one('SELECT id FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);
        if ($variant) {
            $this->db->execute('UPDATE cy_variants SET inventory=inventory+1 WHERE id=? AND content_id=? AND inventory>=0',[$variant,$content]);
            $this->synchronize($content);
        } else { $this->db->execute('UPDATE cy_contents SET inventory=inventory+1 WHERE id=? AND inventory>=0',[$content]); }
    }
    /** Synchronize aggregate stock. Caller must be inside the product transaction. */
    public function synchronize(int $content): void
    {
        $item=$this->db->one('SELECT product_type FROM cy_contents WHERE id=?',[$content]);
        if (!$item) { return; }
        $variants=$this->variants($content,false);
        if ($item['product_type']==='code') {
            foreach ($variants as &$variant) {
                $variant['inventory']=(int)$this->db->value('SELECT COUNT(*) FROM cy_stock_codes WHERE content_id=? AND variant_id=? AND order_id IS NULL',[$content,(int)$variant['id']]);
                $this->db->update('cy_variants',(int)$variant['id'],['inventory'=>$variant['inventory']]);
            } unset($variant);
        }
        if ($variants) {
            $stock=0;
            foreach ($variants as $variant) { if (!(int)$variant['active']) { continue; } if ((int)$variant['inventory']<0) { $stock=-1; break; } $stock+=(int)$variant['inventory']; }
        } elseif ($item['product_type']==='code') {
            $stock=(int)$this->db->value('SELECT COUNT(*) FROM cy_stock_codes WHERE content_id=? AND variant_id=0 AND order_id IS NULL',[$content]);
        } else { return; }
        $this->db->update('cy_contents',$content,['inventory'=>$stock]);
    }
    public function import(int $actor, int $content, string $codes, int $variant=0): int
    {
        $lines=array_values(array_unique(array_filter(array_map('trim',preg_split('/\r\n|\n|\r/',$codes)),static function($line){return $line!=='';})));
        if (!$lines || count($lines)>500) { throw new Problem('Import 1-500 code lines at a time.'); }
        return $this->db->transaction(function() use($actor,$content,$lines,$variant): int {
            $item=$this->db->one("SELECT * FROM cy_contents WHERE id=? AND kind='product' AND product_type='code'".$this->db->lock(),[$content]);
            if (!$item) { throw new Problem('Choose a code-delivery product.'); }
            $variants=$this->variants($content,false);
            if (($variants || $variant) && !$this->db->one('SELECT id FROM cy_variants WHERE id=? AND content_id=?',[$variant,$content])) { throw new Problem('Select an available product variant.'); }
            foreach ($lines as $line) {
                $line=Input::required($line,2000);$hash=$this->crypto->digest('stock:'.$line);
                if ($this->db->one('SELECT id FROM cy_stock_codes WHERE secret_hash=?',[$hash])) { throw new Problem('A delivery code is already present in inventory.'); }
                $this->db->insert('cy_stock_codes',['content_id'=>$content,'variant_id'=>$variant,'secret_cipher'=>$this->crypto->seal($line),'secret_hash'=>$hash,'created_at'=>time()]);
            }
            $this->synchronize($content);$this->activity->audit($actor,'stock.imported',$content.':'.count($lines));return count($lines);
        });
    }
}
