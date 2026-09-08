<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\Input;
/** Bounded, resumable tasks: usable from admin, CLI cron or a signed external scheduler. */
final class Jobs
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function run(int $limit=3): array
    {
        if($this->a->settings->get('recovery_hold')){throw new \Chengyu\Core\Problem('Recovery hold is active. Review restored transactions before enabling background jobs.',503);}
        $limit=Input::integer($limit,1,10);$a=$this->a;$report=['checked'=>0,'completed'=>0,'uncertain'=>0,'items'=>[]];$work=[];
        foreach(['refund'=>'cy_refunds','payout'=>'cy_payouts'] as $type=>$table){foreach($a->db->all("SELECT id,updated_at FROM $table WHERE status IN ('queued','pending','uncertain','running','confirmed') AND lease_until<=? ORDER BY updated_at,id LIMIT ?",[time(),$limit]) as $r){$work[]=[$type,(int)$r['id'],(int)$r['updated_at']];}}
        foreach($a->db->all("SELECT id,last_check_at FROM cy_sales WHERE status='pending' AND attempted_at>0 AND last_check_at<=? ORDER BY last_check_at,id LIMIT ?",[time()-60,$limit]) as $r){$work[]=['payment',(int)$r['id'],(int)$r['last_check_at']];}
        if($a->settings->get('meili_jobs') && $a->remoteSearch->configured()){
            foreach($a->remoteSearch->due($limit) as $r){$work[]=['search',(int)$r['id'],(int)$r['updated_at']];}
        }
        usort($work,static function($x,$y){return $x[2]<=>$y[2];});
        foreach(array_slice($work,0,$limit) as [$type,$id]){
            $report['checked']++;try{$row=$type==='search'?$a->remoteSearch->run($id):($type==='refund'?$a->refunds->run($id):($type==='payout'?$a->payouts->run($id):$a->checkout->reconcile($id)));$status=$row['status'];if($status==='retry'){$report['uncertain']++;}if(in_array($status,['succeeded','paid','closed'],true)){$report['completed']++;}}
            catch(\Throwable $e){$status='uncertain';$report['uncertain']++;}$report['items'][]=['type'=>$type,'id'=>$id,'status'=>$status];
        }return $report;
    }
}
