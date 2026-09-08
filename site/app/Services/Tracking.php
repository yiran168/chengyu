<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{HttpClient,Input,Problem};
/** Carrier events inform customers; they never trigger receipt, refunds or ledger writes. */
final class Tracking
{
    private App $a;private HttpClient $http;
    public function __construct(App $a,?HttpClient $http=null){$this->a=$a;$this->http=$http??new HttpClient();}
    public function attach(int $actor,array $in): int
    {
        $this->a->settings->requireModule('tracking');$order=Input::integer($in['order_id']??0,1);$revision=Input::integer($in['revision']??0);
        $carrier=strtolower(Input::required($in['carrier']??'',40));$number=Input::required($in['tracking_number']??'',32);
        $key=Input::required($in['package_key']??'primary',64);$label=Input::text($in['package_label']??'',100);$explicit=isset($in['package_key']);
        if(!preg_match('/^(?:primary|[a-f0-9]{32,64})$/D',$key)){throw new Problem('Invalid parcel request key.');}
        if(!preg_match('/^[a-z0-9_]{2,40}$/D',$carrier)||!preg_match('/^[a-zA-Z0-9-]{4,32}$/D',$number)){throw new Problem('Use a carrier code and a valid tracking number.');}
        return $this->a->db->transaction(function()use($actor,$order,$revision,$carrier,$number,$key,$label,$explicit):int{
            $db=$this->a->db;$o=$db->one('SELECT * FROM cy_orders WHERE id=?'.$db->lock(),[$order]);if(!$o){throw new Problem('Order not found.',404);}
            $old=$db->one('SELECT * FROM cy_shipments WHERE order_id=? AND package_key=?'.$db->lock(),[$order,$key]);
            if($old && $explicit && $revision===0 && $old['carrier']===$carrier && $this->a->crypto->open($old['tracking_cipher'])===$number && $old['package_label']===$label){return (int)$old['id'];}
            if(($old?(int)$old['revision']:0)!==$revision){throw new Problem('Shipment changed. Reload before saving.',409);}
            $parcels=$db->all('SELECT id,carrier,tracking_cipher FROM cy_shipments WHERE order_id=? ORDER BY id',[$order]);
            if(!$old && count($parcels)>=20){throw new Problem('An order can have at most 20 parcels.');}
            foreach($parcels as $parcel){if((!$old || (int)$parcel['id']!==(int)$old['id']) && $parcel['carrier']===$carrier && $this->a->crypto->open($parcel['tracking_cipher'])===$number){throw new Problem('That waybill is already attached to this order.',409);}}
            $this->a->commerce->ship($actor,$order,$carrier.' '.$number);
            $changed=!$old || $old['carrier']!==$carrier || $this->a->crypto->open($old['tracking_cipher'])!==$number;
            $data=['order_id'=>$order,'package_key'=>$key,'package_label'=>$label,'carrier'=>$carrier,'tracking_cipher'=>$this->a->crypto->seal($number),'revision'=>$revision+1,'updated_at'=>time()];
            if($changed){$data['events_cipher']=$this->a->crypto->seal('[]');$data['state']='pending';$data['queried_at']=0;}
            if($old){$id=(int)$old['id'];$db->update('cy_shipments',$id,$data);if($changed){$db->execute('DELETE FROM cy_tracking_events WHERE shipment_id=?',[$id]);}}
            else{$id=$db->insert('cy_shipments',$data);}
            $this->a->activity->audit($actor,'shipment.saved',(string)$id);return $id;
        });
    }
    public function addEvent(int $actor,int $shipment,int $revision,int $at,string $text): void
    {
        $text=Input::required($text,1000);Input::integer($at,1,time()+86400);
        $this->a->db->transaction(function()use($actor,$shipment,$revision,$at,$text):void{
            $db=$this->a->db;$row=$db->one('SELECT * FROM cy_shipments WHERE id=?'.$db->lock(),[$shipment]);if(!$row){throw new Problem('Shipment not found.',404);}
            if((int)$row['revision']!==$revision){throw new Problem('Shipment changed. Reload before saving.',409);}
            if((int)$db->value('SELECT COUNT(*) FROM cy_tracking_events WHERE shipment_id=?',[$shipment])>=200){throw new Problem('Shipment timeline limit reached.');}
            $db->insert('cy_tracking_events',['shipment_id'=>$shipment,'event_at'=>$at,'description'=>$this->a->crypto->seal($text),'actor_id'=>$actor,'created_at'=>time()]);
            $db->update('cy_shipments',$shipment,['revision'=>$revision+1,'updated_at'=>time()]);$this->a->activity->audit($actor,'shipment.event',(string)$shipment);
        });
    }
    private function authorizedOrder(int $order,?array $user): array
    {
        $this->a->settings->requireModule('tracking');$o=$this->a->db->one('SELECT id,user_id,order_no FROM cy_orders WHERE id=?',[$order]);
        if(!$o || !$user || ((int)$o['user_id']!==(int)$user['id'] && $user['role']!=='admin')){throw new Problem('Order not found.',404);}return $o;
    }
    public function parcels(int $order,?array $user): array
    {
        $this->authorizedOrder($order,$user);$rows=$this->a->db->all('SELECT id,package_key,package_label,carrier,tracking_cipher,state,revision,queried_at,updated_at FROM cy_shipments WHERE order_id=? ORDER BY id',[$order]);
        foreach($rows as &$row){$row['tracking_number']=$this->a->crypto->open($row['tracking_cipher']);unset($row['tracking_cipher']);}unset($row);return $rows;
    }
    public function view(int $order,?array $user,int $shipment=0): array
    {
        $o=$this->authorizedOrder($order,$user);
        $row=$shipment?$this->a->db->one('SELECT * FROM cy_shipments WHERE order_id=? AND id=?',[$order,$shipment]):$this->a->db->one('SELECT * FROM cy_shipments WHERE order_id=? ORDER BY id LIMIT 1',[$order]);
        if(!$row){throw new Problem('Shipment not found.',404);}
        $row['order_no']=$o['order_no'];$row['tracking_number']=$this->a->crypto->open($row['tracking_cipher']);$row['events']=json_decode($this->a->crypto->open($row['events_cipher']),true,32,JSON_THROW_ON_ERROR);
        foreach($row['events'] as &$event){$event['source']='Carrier update';}unset($event);
        foreach($this->a->db->all('SELECT * FROM cy_tracking_events WHERE shipment_id=? ORDER BY event_at DESC,id DESC',[(int)$row['id']]) as $event){$row['events'][]=['at'=>(int)$event['event_at'],'text'=>$this->a->crypto->open($event['description']),'source'=>'Merchant update'];}
        usort($row['events'],static function(array $a,array $b):int{return $b['at']<=>$a['at'];});unset($row['tracking_cipher'],$row['events_cipher']);return $row;
    }
    /** Caller must obtain explicit consent before sending the waybill and optional phone. */
    public function refresh(int $actor,int $shipment,string $phone=''): void
    {
        $this->a->settings->requireModule('tracking');$s=$this->a->settings;
        if($s->get('tracking_provider')!=='kuaidi100' || $s->get('tracking_customer')==='' || $s->get('tracking_key')===''){throw new Problem('Configure the tracking provider first.',503);}
        $phone=Input::text($phone,24);if($phone!==''&&!preg_match('/^[0-9+() -]{4,24}$/D',$phone)){throw new Problem('Invalid telephone number.');}
        $row=$this->a->db->transaction(function()use($shipment,$s,$phone):array{
            $db=$this->a->db;$row=$db->one('SELECT * FROM cy_shipments WHERE id=?'.$db->lock(),[$shipment]);if(!$row){throw new Problem('Shipment not found.',404);}
            if(in_array($row['carrier'],['shunfeng','fengwang'],true) && $phone===''){throw new Problem('This carrier requires the recipient or sender phone number.');}
            if((int)$row['queried_at']>time()-(int)$s->get('tracking_interval')){throw new Problem('Tracking was queried recently. Use the cached timeline.',429);}
            $db->update('cy_shipments',$shipment,['queried_at'=>time()]);return $row;
        });
        $number=$this->a->crypto->open($row['tracking_cipher']);$param=['com'=>$row['carrier'],'num'=>$number,'resultv2'=>'4','show'=>'0','order'=>'desc'];if($phone!==''){$param['phone']=$phone;}
        $json=json_encode($param,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$customer=(string)$s->get('tracking_customer');
        $result=$this->http->json('POST','https://poll.kuaidi100.com/poll/query.do',['customer'=>$customer,'sign'=>strtoupper(md5($json.$s->get('tracking_key').$customer)),'param'=>$json]);
        if((string)($result['status']??'')!=='200' || !is_array($result['data']??null) || strtolower((string)($result['com']??''))!==$row['carrier'] || (string)($result['nu']??'')!==$number){throw new Problem('The carrier did not return a matching successful result.',502);}
        $events=[];foreach(array_slice($result['data'],0,200) as $event){if(!is_array($event)){throw new Problem('Invalid carrier timeline.',502);}$time=Input::text($event['ftime']??$event['time']??'',30);$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$time,new \DateTimeZone('Asia/Shanghai'));if(!$date || $date->format('Y-m-d H:i:s')!==$time){throw new Problem('Invalid carrier timeline date.',502);}$events[]=['at'=>$date->getTimestamp(),'text'=>Input::required($event['context']??'',1000)];}
        $state=Input::text($result['state']??'unknown',30);if(!preg_match('/^[0-9a-z_]{1,30}$/D',$state)){throw new Problem('Invalid carrier state.',502);}
        $this->a->db->transaction(function()use($actor,$shipment,$row,$events,$state):void{
            $db=$this->a->db;$fresh=$db->one('SELECT * FROM cy_shipments WHERE id=?'.$db->lock(),[$shipment]);if(!$fresh || (int)$fresh['revision']!==(int)$row['revision']){throw new Problem('Shipment changed while querying. The stale result was discarded.',409);}
            $db->update('cy_shipments',$shipment,['events_cipher'=>$this->a->crypto->seal(json_encode($events,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)),'state'=>$state,'revision'=>(int)$row['revision']+1,'updated_at'=>time()]);$this->a->activity->audit($actor,'shipment.queried',(string)$shipment);
        });
    }
}
