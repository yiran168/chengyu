<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
/** Untrusted statement imports produce read-only exceptions, NEVER credit or refund money. */
final class Reconciliation
{
    public const HEADER=['event_type','reference','trade_id','currency','amount_minor','state','event_at'];
    public const CURRENCIES=['CNY','USD','EUR','GBP','HKD','SGD','CAD','AUD'];
    private App $a;public function __construct(App $a){$this->a=$a;}
    public static function timestamp(string $value): int
    {
        // A mandatory offset avoids server/merchant timezone ambiguity.
        if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D',$value)){throw new Problem('Use an ISO 8601 timestamp with timezone.');}
        try{$d=new \DateTimeImmutable($value);}catch(\Throwable $e){throw new Problem('Invalid statement date.');}
        $errors=\DateTimeImmutable::getLastErrors();if($errors && ($errors['warning_count']||$errors['error_count'])){throw new Problem('Invalid statement date.');}
        return $d->getTimestamp();
    }
    private static function identifier(string $value,int $max,bool $empty=false): string
    {
        if($value==='' && $empty){return '';}
        if(strlen($value)>$max || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D',$value)){throw new Problem('Statement contains an invalid reference or trade ID.');}return $value;
    }
    public function import(int $actor,string $gateway,string $csv,int $start,int $end): int
    {
        Input::choice($gateway,array_keys(DirectPayments::NAMES));
        if($start<1 || $end<=$start || $end-$start>2678400){throw new Problem('Use a statement window of at most 31 days.');}
        if(strlen($csv)>2097152 || !preg_match('//u',$csv) || strpos($csv,"\0")!==false){throw new Problem('Use a UTF-8 CSV no larger than 2 MiB.');}
        $csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);$stream=fopen('php://temp','w+');if(!$stream){throw new \RuntimeException('Cannot open statement buffer');}fwrite($stream,$csv);rewind($stream);
        $rows=[];$seen=[];
        try{
            $header=fgetcsv($stream,32768,',','"','');if($header!==self::HEADER){throw new Problem('The statement CSV header does not match the documented format.');}
            while(($row=fgetcsv($stream,32768,',','"',''))!==false){if($row===[null]){continue;}if(count($rows)>=2000 || count($row)!==7){throw new Problem('Statement rows must have seven fields; maximum 2000 rows.');}
                [$type,$reference,$trade,$currency,$amount,$state,$date]=$row;$type=Input::choice($type,['payment','refund']);$reference=self::identifier($reference,80);$trade=self::identifier($trade,191,true);$currency=Input::choice($currency,self::CURRENCIES);$state=Input::choice($state,['succeeded','pending','failed']);
                if(!preg_match('/^(0|[1-9][0-9]{0,11})$/D',$amount)){throw new Problem('Statement amounts must be non-negative integer minor units.');}$at=self::timestamp($date);
                if($at<$start || $at>=$end){throw new Problem('Statement row is outside the selected half-open time window.');}
                if($state==='succeeded' && $trade===''){throw new Problem('Successful statement rows require a provider trade ID.');}
                $key=$type.':'.$reference;if(isset($seen[$key])){throw new Problem('Duplicate reference in statement. Split different statement events before import.');}$seen[$key]=true;
                $rows[]=['event_type'=>$type,'reference'=>$reference,'trade_id'=>$trade,'currency'=>$currency,'amount_minor'=>(int)$amount,'external_state'=>$state,'event_at'=>$at,'outcome'=>'unchecked','message'=>'','local_id'=>0];
            }
        }finally{fclose($stream);}
        if(!$rows){throw new Problem('Statement is empty.');}$hash=hash('sha256',$csv);
        return $this->a->db->transaction(function()use($actor,$gateway,$rows,$hash,$start,$end):int{
            $db=$this->a->db;$db->one('SELECT value FROM cy_settings WHERE name=?'.$db->lock(),['_schema_version']);
            $old=$db->one('SELECT id FROM cy_recon_batches WHERE gateway=? AND file_hash=? AND starts_at=? AND ends_at=?',[$gateway,$hash,$start,$end]);if($old){return (int)$old['id'];}
            $id=$db->insert('cy_recon_batches',['gateway'=>$gateway,'file_hash'=>$hash,'starts_at'=>$start,'ends_at'=>$end,'actor_id'=>$actor,'created_at'=>time()]);
            foreach($rows as $row){$row['batch_id']=$id;$db->insert('cy_recon_rows',$row);}$this->check($actor,$id);$this->a->activity->audit($actor,'statement.imported',(string)$id);return $id;
        });
    }
    private function local(array $row,string $gateway): ?array
    {
        if($row['event_type']==='payment'){
            $s=$this->a->db->one('SELECT * FROM cy_sales WHERE sale_no=? AND gateway=?',[$row['reference'],$gateway]);if(!$s){return null;}
            return ['id'=>(int)$s['id'],'amount'=>(int)$s['amount'],'currency'=>$s['currency'],'trade'=>$s['trade_no'],'state'=>$s['status']==='paid'?'succeeded':(in_array($s['status'],['closed','cancelled','failed'],true)?'failed':'pending'),'at'=>(int)$s['paid_at'],'receipt'=>$s['status']!=='paid'||(bool)$this->a->db->one('SELECT id FROM cy_receipts WHERE sale_id=?',[(int)$s['id']])];
        }
        $r=$this->a->db->one('SELECT r.*,s.currency,s.gateway FROM cy_refunds r JOIN cy_sales s ON s.id=r.sale_id WHERE r.refund_no=? AND s.gateway=?',[$row['reference'],$gateway]);if(!$r){return null;}
        return ['id'=>(int)$r['id'],'amount'=>(int)$r['amount'],'currency'=>$r['currency'],'trade'=>$r['remote_id'],'state'=>$r['status']==='succeeded'?'succeeded':($r['status']==='failed'?'failed':'pending'),'at'=>(int)$r['updated_at'],'receipt'=>$r['status']!=='succeeded'||$this->a->db->value('SELECT status FROM cy_orders WHERE id=?',[(int)$r['order_id']])==='refunded'];
    }
    public function check(int $actor,int $batch): void
    {
        $this->a->db->transaction(function()use($actor,$batch):void{
            $db=$this->a->db;$b=$db->one('SELECT * FROM cy_recon_batches WHERE id=?'.$db->lock(),[$batch]);if(!$b){throw new Problem('Statement not found.',404);}
            // Generated local-only rows are rebuilt, never confused with imported rows.
            $db->execute("DELETE FROM cy_recon_rows WHERE batch_id=? AND external_state='absent'",[$batch]);
            foreach($db->all('SELECT * FROM cy_recon_rows WHERE batch_id=? ORDER BY id',[$batch]) as $row){$local=$this->local($row,$b['gateway']);$outcome='matched';$message='Amount, currency, trade and state agree.';
                if(!$local){$outcome='external_only';$message='No local transaction has this gateway and reference.';}
                elseif($local['currency']!==$row['currency']){$outcome='currency_mismatch';$message='Currencies differ. Never convert or relabel automatically.';}
                elseif($local['amount']!==(int)$row['amount_minor']){$outcome='amount_mismatch';$message='Gross minor-unit amounts differ. Fees belong in a separate accounting process.';}
                elseif($row['trade_id']!=='' && $local['trade']!==$row['trade_id']){$outcome='trade_mismatch';$message='Provider transaction identifiers differ.';}
                elseif($local['state']!==$row['external_state']){$outcome='state_mismatch';$message='Query the original provider before changing any local transaction.';}
                elseif(!$local['receipt']){$outcome='ledger_mismatch';$message='Local receipt or reversal evidence is missing.';}
                elseif($local['state']==='succeeded' && ($local['at']<(int)$b['starts_at'] || $local['at']>=(int)$b['ends_at'])){$outcome='window_mismatch';$message='Local finalization time is outside the imported window.';}
                $db->update('cy_recon_rows',(int)$row['id'],['local_id'=>$local?$local['id']:0,'outcome'=>$outcome,'message'=>$message]);
            }
            $missing=$db->all("SELECT s.id,s.sale_no AS reference,s.trade_no AS trade_id,s.amount AS amount_minor,s.currency,s.paid_at AS event_at FROM cy_sales s WHERE s.gateway=? AND s.status='paid' AND s.paid_at>=? AND s.paid_at<? AND NOT EXISTS(SELECT 1 FROM cy_recon_rows r WHERE r.batch_id=? AND r.event_type='payment' AND r.reference=s.sale_no) LIMIT 2001",[$b['gateway'],(int)$b['starts_at'],(int)$b['ends_at'],$batch]);
            $refunds=$db->all("SELECT r.id,r.refund_no AS reference,r.remote_id AS trade_id,r.amount AS amount_minor,s.currency,r.updated_at AS event_at FROM cy_refunds r JOIN cy_sales s ON s.id=r.sale_id WHERE s.gateway=? AND r.status='succeeded' AND r.updated_at>=? AND r.updated_at<? AND NOT EXISTS(SELECT 1 FROM cy_recon_rows rr WHERE rr.batch_id=? AND rr.event_type='refund' AND rr.reference=r.refund_no) LIMIT 2001",[$b['gateway'],(int)$b['starts_at'],(int)$b['ends_at'],$batch]);
            if(count($missing)+count($refunds)>2000){throw new Problem('Too many local-only rows. Import a shorter statement window.');}
            foreach(['payment'=>$missing,'refund'=>$refunds] as $type=>$rows){foreach($rows as $row){$local=(int)$row['id'];unset($row['id']);$db->insert('cy_recon_rows',array_merge($row,['batch_id'=>$batch,'event_type'=>$type,'external_state'=>'absent','local_id'=>$local,'outcome'=>'local_only','message'=>'Local success is missing from this statement. Verify its scope and finalization timezone.']));}}
            $db->update('cy_recon_batches',$batch,['checked_at'=>time()]);$this->a->activity->audit($actor,'statement.checked',(string)$batch);
        });
    }
    public function summary(int $id): array
    {
        $b=$this->a->db->one('SELECT * FROM cy_recon_batches WHERE id=?',[$id]);if(!$b){throw new Problem('Statement not found.',404);}
        $b['counts']=$this->a->db->all('SELECT outcome,COUNT(*) AS count FROM cy_recon_rows WHERE batch_id=? GROUP BY outcome',[$id]);return $b;
    }
    public function rows(int $batch,int $page=1): array
    {Input::integer($page,1,100000);return $this->a->db->all('SELECT * FROM cy_recon_rows WHERE batch_id=? ORDER BY id LIMIT 50 OFFSET ?',[$batch,($page-1)*50]);}
}
