<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};
/** Versioned data-only business rules; no PHP, SQL or templates can be executed. */
final class Rulebooks
{
    private Database $db;private Settings $settings;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Activity $activity){$this->db=$db;$this->settings=$settings;$this->activity=$activity;}
    private static function table(string $kind): string {return ['shipping'=>'cy_shipping_templates','promotion'=>'cy_promotions'][Input::choice($kind,['shipping','promotion'])];}
    public function all(string $kind): array{return $this->db->all('SELECT * FROM '.self::table($kind).' ORDER BY id DESC LIMIT 200');}
    private static function rate(array $d): array
    {
        return ['first'=>Input::integer($d['first']??1,1,1000000),'first_fee'=>Input::integer($d['first_fee']??0,0,100000000),
            'step'=>Input::integer($d['step']??1,1,1000000),'step_fee'=>Input::integer($d['step_fee']??0,0,100000000),
            'free_above'=>Input::integer($d['free_above']??0,0,1000000000)];
    }
    public static function validate(string $kind,array $d): array
    {
        if($kind==='shipping'){
            $out=['mode'=>Input::choice($d['mode']??'piece',['fixed','piece','weight']),'default'=>null,'zones'=>[]];
            if(isset($d['default'])){if(!is_array($d['default'])){throw new Problem('Invalid default shipping rule.');}$out['default']=self::rate($d['default']);}
            if(!is_array($d['zones']??[])||count($d['zones']??[])>50){throw new Problem('Use at most 50 shipping zones.');}
            foreach($d['zones']??[] as $code=>$rate){$code=Input::required($code,40);if(!preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$code)||!is_array($rate)){throw new Problem('Invalid shipping region code.');}$out['zones'][$code]=self::rate($rate);}
            if($out['default']===null&&!$out['zones']){throw new Problem('A shipping template must serve at least one region.');}return $out;
        }
        Input::choice($kind,['promotion']);
        $out=['type'=>Input::choice($d['type']??'',['threshold_fixed','percent','nth','buy_get','tiered','bundle']),
            'group'=>Input::text($d['group']??'order',32),'min_amount'=>Input::integer($d['min_amount']??0,0,1000000000),
            'min_tier'=>Input::integer($d['min_tier']??0,0,3),'item_ids'=>[]];
        if(!is_array($d['item_ids']??[])||count($d['item_ids']??[])>100){throw new Problem('Invalid promotion product scope.');}
        foreach($d['item_ids']??[] as $id){$out['item_ids'][]=Input::integer($id,1);}$out['item_ids']=array_values(array_unique($out['item_ids']));sort($out['item_ids'],SORT_NUMERIC);
        if($out['group']===''){$out['group']='order';}
        switch($out['type']){
            case 'threshold_fixed':$out['discount']=Input::integer($d['discount']??0,1,1000000000);break;
            case 'percent':$out['discount_bps']=Input::integer($d['discount_bps']??0,1,10000);break;
            case 'nth':$out['nth']=Input::integer($d['nth']??2,2,100);$out['discount_bps']=Input::integer($d['discount_bps']??5000,1,10000);break;
            case 'buy_get':$out['buy']=Input::integer($d['buy']??2,1,99);$out['get']=Input::integer($d['get']??1,1,99);break;
            case 'tiered':
                if(!is_array($d['tiers']??null)||!count($d['tiers'])||count($d['tiers'])>20){throw new Problem('Configure 1-20 quantity tiers.');}$out['tiers']=[];$seen=[];
                foreach($d['tiers'] as $t){if(!is_array($t)){throw new Problem('Invalid quantity tier.');}$q=Input::integer($t['quantity']??0,1,100);if(isset($seen[$q])){throw new Problem('Duplicate quantity tier.');}$seen[$q]=true;$out['tiers'][]=['quantity'=>$q,'discount_bps'=>Input::integer($t['discount_bps']??0,1,10000)];}
                usort($out['tiers'],static function($a,$b){return $a['quantity']<=>$b['quantity'];});break;
            case 'bundle':
                if(count($out['item_ids'])<2){throw new Problem('A bundle needs at least two distinct products.');}$out['discount']=Input::integer($d['discount']??0,1,1000000000);break;
        }return $out;
    }
    public function save(int $actor,string $kind,array $in): int
    {
        $table=self::table($kind);$id=Input::integer($in['id']??0);$revision=Input::integer($in['revision']??0);
        $raw=Input::required($in['document']??'',32768);try{$d=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new Problem('Invalid rule JSON.');}if(!is_array($d)){throw new Problem('The rule must be a JSON object.');}
        $d=self::validate($kind,$d);$row=['name'=>Input::required($in['name']??'',100),'document'=>json_encode($d,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'active'=>empty($in['active'])?0:1,'updated_at'=>time()];
        if($kind==='promotion'){$row['starts_at']=Input::integer($in['starts_at']??0,0,4102444800);$row['ends_at']=Input::integer($in['ends_at']??0,0,4102444800);if($row['ends_at']&&$row['ends_at']<=$row['starts_at']){throw new Problem('Promotion end must be after its start.');}}
        return $this->db->transaction(function()use($table,$kind,$id,$revision,$row,$actor):int{
            if($id){$old=$this->db->one('SELECT * FROM '.$table.' WHERE id=?'.$this->db->lock(),[$id]);if(!$old){throw new Problem('Rule not found.',404);}if((int)$old['revision']!==$revision){throw new Problem('This rule changed. Reload before saving.',409);}$row['revision']=$revision+1;$this->db->update($table,$id,$row);}
            else{if((int)$this->db->value('SELECT COUNT(*) FROM '.$table)>=200){throw new Problem('Rule limit reached. Edit an existing rule.');}$row['revision']=1;$id=$this->db->insert($table,$row);}
            $this->activity->audit($actor,$kind.'.saved',(string)$id);return $id;
        });
    }
    public function bindShipping(int $actor,int $content,int $template,int $grams): void
    {
        Input::integer($grams,0,1000000);Input::integer($template);
        $this->db->transaction(function()use($actor,$content,$template,$grams):void{
            $p=$this->db->one('SELECT * FROM cy_contents WHERE id=?'.$this->db->lock(),[$content]);if(!$p||$p['kind']!=='product'||$p['product_type']!=='physical'){throw new Problem('Choose a physical product.');}
            if($template&&!$this->db->one('SELECT id FROM cy_shipping_templates WHERE id=? AND active=1',[$template])){throw new Problem('Choose an active shipping template.');}
            $this->db->update('cy_contents',$content,['shipping_template_id'=>$template,'weight_grams'=>$grams]);$this->activity->audit($actor,'shipping.bound',(string)$content);
        });
    }
    /** Exact integer allocation: totals never lose a cent and no unit goes negative. */
    public static function allocate(int $total,array $weights): array
    {
        if($total<0||count($weights)>100){throw new Problem('Invalid allocation.');}$sum=0;foreach($weights as $w){$sum+=Input::integer($w,0,1000000000);}
        $out=array_fill_keys(array_keys($weights),0);if(!$total){return $out;}if(!$weights){throw new Problem('Cannot allocate to an empty order.');}
        if(!$sum){$n=count($weights);foreach($out as $k=>$unused){$out[$k]=intdiv($total,$n);} $left=$total%$n;foreach($out as $k=>$unused){if(!$left--){break;}$out[$k]++;}return $out;}
        $remainders=[];$used=0;foreach($weights as $k=>$w){$out[$k]=intdiv($total*$w,$sum);$used+=$out[$k];$remainders[$k]=($total*$w)%$sum;}
        arsort($remainders,SORT_NUMERIC);$left=$total-$used;foreach($remainders as $k=>$unused){if(!$left--){break;}$out[$k]++;}return $out;
    }
    /** Input units carry a snapshot of the product and their current discounted amount. */
    public function shipping(array $units,string $region): array
    {
        $region=Input::text($region,40);$fees=array_fill(0,count($units),0);$groups=[];$details=[];
        foreach($units as $i=>$u){$p=$u['item'];if($p['kind']==='product'&&$p['product_type']==='physical'){$groups[(int)$p['shipping_template_id']][]=$i;}}
        foreach($groups as $id=>$indexes){if(!$id){$details[]=['template'=>0,'amount'=>0,'region'=>$region];continue;}
            $this->settings->requireModule('shipping');$row=$this->db->one('SELECT * FROM cy_shipping_templates WHERE id=? AND active=1',[$id]);if(!$row){throw new Problem('The shipping template is unavailable.');}$d=json_decode($row['document'],true,32,JSON_THROW_ON_ERROR);
            $rate=$d['zones'][$region]??$d['default'];if(!$rate){throw new Problem('This product cannot ship to the selected region.');}
            $count=0;$sum=0;$weights=[];foreach($indexes as $i){$p=$units[$i]['item'];if($d['mode']==='weight'&&(int)$p['weight_grams']<=0){throw new Problem('The merchant must configure a positive product weight.');}$count+=$d['mode']==='weight'?(int)$p['weight_grams']:1;$sum+=$units[$i]['amount'];$weights[$i]=$units[$i]['amount'];}
            $fee=(int)$rate['first_fee'];if($d['mode']!=='fixed'&&$count>$rate['first']){$fee+=intdiv($count-$rate['first']+$rate['step']-1,$rate['step'])*$rate['step_fee'];}if($rate['free_above']>0&&$sum>=$rate['free_above']){$fee=0;}if($fee>1000000000){throw new Problem('Shipping amount exceeds the order limit.');}
            foreach(self::allocate($fee,$weights) as $i=>$f){$fees[$i]=$f;}$details[]=['template'=>$id,'revision'=>(int)$row['revision'],'amount'=>$fee,'region'=>$region];
        }return ['fees'=>$fees,'amount'=>array_sum($fees),'details'=>$details];
    }
    public function promotions(array $units,int $tier): array
    {
        $amounts=array_map(static function($u){return (int)$u['amount'];},$units);$applied=[];
        if(!$this->settings->enabled('promotions')){return ['amounts'=>$amounts,'applied'=>[]];}
        $rows=$this->db->all('SELECT * FROM cy_promotions WHERE active=1 AND starts_at<=? AND (ends_at=0 OR ends_at>?) ORDER BY id LIMIT 200',[time(),time()]);$groups=[];
        foreach($rows as $r){$d=json_decode($r['document'],true,32,JSON_THROW_ON_ERROR);if($tier<$d['min_tier']){continue;}$groups[$d['group']][]=['row'=>$r,'rule'=>$d];}ksort($groups,SORT_STRING);
        foreach($groups as $candidates){$best=[];$saving=0;$winner=null;
            foreach($candidates as $c){$d=$c['rule'];$eligible=[];foreach($units as $i=>$u){if(!$d['item_ids']||in_array((int)$u['item']['id'],$d['item_ids'],true)){$eligible[$i]=$amounts[$i];}}
                if(!$eligible||array_sum($eligible)<$d['min_amount']){continue;}$cut=array_fill_keys(array_keys($eligible),0);$count=count($eligible);$sum=array_sum($eligible);
                if($d['type']==='threshold_fixed'){$cut=self::allocate(min($sum,$d['discount']),$eligible);}
                elseif($d['type']==='percent'){$cut=self::allocate(intdiv($sum*$d['discount_bps'],10000),$eligible);}
                elseif($d['type']==='bundle'){$present=[];foreach(array_keys($eligible) as $i){$present[(int)$units[$i]['item']['id']]=true;}if(count(array_intersect($d['item_ids'],array_keys($present)))===count($d['item_ids'])){$cut=self::allocate(min($sum,$d['discount']),$eligible);}}
                elseif($d['type']==='tiered'){$bps=0;foreach($d['tiers'] as $t){if($count>=$t['quantity']){$bps=$t['discount_bps'];}}$cut=self::allocate(intdiv($sum*$bps,10000),$eligible);}
                else{$n=$d['type']==='nth'?intdiv($count,$d['nth']):intdiv($count,$d['buy']+$d['get'])*$d['get'];asort($eligible,SORT_NUMERIC);foreach($eligible as $i=>$amount){if($n--<=0){break;}$cut[$i]=$d['type']==='nth'?intdiv($amount*$d['discount_bps'],10000):$amount;}}
                $s=array_sum($cut);if($s>$saving){$saving=$s;$best=$cut;$winner=$c['row'];}
            }
            if($winner){foreach($best as $i=>$cut){$amounts[$i]-=$cut;}$applied[]=['id'=>(int)$winner['id'],'revision'=>(int)$winner['revision'],'name'=>$winner['name'],'saving'=>$saving];}
        }return ['amounts'=>$amounts,'applied'=>$applied];
    }
}
