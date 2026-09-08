<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Input,Problem};

/** One definition of a listed price, shared by filtering and ordering. */
final class CatalogFilters
{
    public const PRICE = 'COALESCE((SELECT MIN(v.price_amount) FROM cy_variants v WHERE v.content_id=c.id AND v.active=1 AND v.inventory<>0),c.price_amount)';
    public const SORTS = ['latest','popular','relevance','price','price_desc','sales','updated'];
    public const KEYS = ['access','product_type','currency','min_price','max_price','stock','tag','period'];

    public static function normalize(array $in): array
    {
        $out=[];
        foreach(['access'=>['','public','login','vip','paid','reply','password'],'product_type'=>['','digital','code','physical','invitation'],'currency'=>['','balance','points','tokens'],'stock'=>['','available'],'period'=>['','week','month','year']] as $key=>$choices){
            $out[$key]=Input::choice($in[$key]??'',$choices);
        }
        $out['tag']=Input::text($in['tag']??'',50);
        if(strpos($out['tag'],',')!==false){throw new Problem('Choose one tag at a time.');}
        foreach(['min_price','max_price'] as $key){$raw=Input::text($in[$key]??'',20);$out[$key]=$raw;}
        // Never compare different assets as though points were money.
        if($out['min_price']!=='' || $out['max_price']!=='' || in_array($in['sort']??'',['price','price_desc'],true)){$out['currency']=$out['currency']?:'balance';}
        $limits=[];foreach(['min_price','max_price'] as $key){if($out[$key]!==''){$limits[$key]=$out['currency']==='balance'?Input::cents($out[$key],true):Input::integer($out[$key],0,100000000);}}
        if(isset($limits['min_price'],$limits['max_price']) && $limits['min_price']>$limits['max_price']){throw new Problem('Minimum price must not exceed maximum price.');}
        return $out;
    }

    public static function apply(array $in,array &$where,array &$params,string $driver): void
    {
        $f=self::normalize($in);
        foreach(['access'=>'access_level','product_type'=>'product_type','currency'=>'price_currency'] as $key=>$column){if($f[$key]!==''){$where[]='c.'.$column.'=?';$params[]=$f[$key];}}
        foreach(['min_price'=>'>=','max_price'=>'<='] as $key=>$op){if($f[$key]!==''){$where[]=self::PRICE.$op.'?';$params[]=$f['currency']==='balance'?Input::cents($f[$key],true):Input::integer($f[$key],0,100000000);}}
        if($f['product_type']!=='' || $f['stock']!==''){$where[]="c.kind='product'";}
        if($f['stock']==='available'){$where[]='((NOT EXISTS(SELECT 1 FROM cy_variants sv WHERE sv.content_id=c.id) AND c.inventory<>0) OR EXISTS(SELECT 1 FROM cy_variants sv WHERE sv.content_id=c.id AND sv.active=1 AND sv.inventory<>0))';}
        if($f['tag']!==''){
            $tags=$driver==='mysql'?"CONCAT(',',REPLACE(c.tags,', ',','),',')":"(',' || REPLACE(c.tags,', ',',') || ',')";
            $where[]=$tags." LIKE ? ESCAPE '!'";$params[]='%,'.str_replace(['!','%','_'],['!!','!%','!_'],$f['tag']).',%';
        }
        if($f['period']!==''){$where[]='c.created_at>=?';$params[]=time()-['week'=>604800,'month'=>2592000,'year'=>31536000][$f['period']];}
    }
}
