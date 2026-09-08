<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\App;
use Chengyu\Core\{Input,Problem};
use Chengyu\Payments\{PayPal,Tools};
/** PayPal Payouts only. Other payout rails are deliberately not simulated. */
final class Payouts
{
    private App $a;public function __construct(App $a){$this->a=$a;}
    public function queue(int $actor,int $withdrawal): int
    {
        $a=$this->a;$a->settings->requireModule('automatic_payouts');$currency=(string)$a->settings->get('store_currency');$config=$a->directPayments->snapshot('paypal',$currency);
        return $a->db->transaction(function()use($a,$actor,$withdrawal,$currency,$config):int{
            $w=$a->db->one('SELECT * FROM cy_withdrawals WHERE id=?'.$a->db->lock(),[$withdrawal]);if(!$w){throw new Problem('Withdrawal not found.',404);}
            $old=$a->db->one('SELECT id FROM cy_payouts WHERE withdrawal_id=?',[$withdrawal]);if($old){return (int)$old['id'];}
            if($w['status']!=='pending'||(int)$w['amount']>(int)$a->settings->get('payout_max')){throw new Problem('This withdrawal is not eligible for automatic payout.',409);}
            $user=$a->wallet->lockActive((int)$w['user_id']);if((int)$user[$w['currency']]<0){throw new Problem('Resolve the account debt before approving a payout.',409);}
            $email=trim($a->crypto->open($w['account_cipher']));if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>191){throw new Problem('The original withdrawal destination must be a PayPal email address. It cannot be replaced during approval.');}
            $id=$a->db->insert('cy_payouts',['withdrawal_id'=>$withdrawal,'batch_key'=>Commerce::orderNumber(),'status'=>'queued','config_cipher'=>$a->crypto->seal(json_encode($config,JSON_THROW_ON_ERROR)),'receiver_cipher'=>$a->crypto->seal($email),'currency'=>$currency,'amount'=>(int)$w['amount'],'created_at'=>time(),'updated_at'=>time()]);
            $a->db->update('cy_withdrawals',$withdrawal,['status'=>'processing','note'=>'PayPal payout approved; reserved funds remain held until a verified result.','updated_at'=>time()]);$a->activity->audit($actor,'payout.approved',(string)$id);return $id;
        });
    }
    public function run(int $id): array
    {
        $a=$this->a;$p=$a->db->one('SELECT * FROM cy_payouts WHERE id=?',[$id]);if(!$p){throw new Problem('Payout not found.',404);}if(in_array($p['status'],['succeeded','failed'],true)){return $p;}
        $claimed=$a->db->transaction(function()use($a,$id):?array{
            $p=$a->db->one('SELECT * FROM cy_payouts WHERE id=?'.$a->db->lock(),[$id]);if(in_array($p['status'],['succeeded','failed'],true)||(int)$p['lease_until']>time()){return null;}
            $w=$a->db->one('SELECT * FROM cy_withdrawals WHERE id=?'.$a->db->lock(),[(int)$p['withdrawal_id']]);if(!$w||$w['status']!=='processing'){throw new Problem('Payout escrow is inconsistent.',409);}
            $user=$a->wallet->lockActive((int)$w['user_id']);if(!(int)$p['attempted_at']&&(int)$user[$w['currency']]<0){throw new Problem('Payout blocked by a new earnings reversal. Review the reserved withdrawal.',409);}
            $a->db->update('cy_payouts',$id,['status'=>'running','lease_until'=>time()+90,'attempts'=>(int)$p['attempts']+1,'attempted_at'=>(int)$p['attempted_at']?:time(),'updated_at'=>time()]);return $p;
        });if(!$claimed){return $a->db->one('SELECT * FROM cy_payouts WHERE id=?',[$id]);}
        try{
            $driver=$a->directPayments->driver('paypal',json_decode($a->crypto->open($claimed['config_cipher']),true,32,JSON_THROW_ON_ERROR));if(!$driver instanceof PayPal){throw new \LogicException('Payout driver mismatch');}
            $email=$a->crypto->open($claimed['receiver_cipher']);
            if($claimed['remote_id']===''){
                // sender_batch_id is permanent locally; never manufacture another ID after a timeout.
                if((int)$claimed['attempted_at']&&time()-(int)$claimed['attempted_at']>86400){throw new Problem('Unknown payout outcome. Locate the original batch in PayPal before any further action.',409);}
                $response=$driver->createPayout($claimed,$email);$remote=Tools::identifier($response['batch_header']['payout_batch_id']??'');
                if(($response['batch_header']['sender_batch_header']['sender_batch_id']??'')!==$claimed['batch_key']){throw new Problem('Payout batch binding mismatch.',409);}
                $a->db->update('cy_payouts',$id,['remote_id'=>$remote]);$claimed['remote_id']=$remote;
            }
            $response=$driver->queryPayout($claimed['remote_id']);$head=$response['batch_header']??[];
            if(($head['payout_batch_id']??'')!==$claimed['remote_id']||($head['sender_batch_header']['sender_batch_id']??'')!==$claimed['batch_key']){throw new Problem('Payout batch binding mismatch.',409);}
            $items=$response['items']??[];$state='pending';
            if(count($items)>1){throw new Problem('Unexpected payout item count.',409);}
            if(count($items)===1){$item=$items[0];$data=$item['payout_item']??[];
                if(($data['sender_item_id']??'')!==$claimed['batch_key']||strtolower((string)($data['receiver']??''))!==strtolower($email)||($data['amount']['currency']??'')!==$claimed['currency']||Input::cents($data['amount']['value']??'',true)!==(int)$claimed['amount']){throw new Problem('Payout recipient, amount or currency mismatch.',409);}
                $status=$item['transaction_status']??'';if($status==='SUCCESS'){$state='succeeded';}elseif(in_array($status,['FAILED','RETURNED','DENIED','CANCELED'],true)){$state='failed';}
            }
            $a->db->transaction(function()use($a,$id,$state):void{
                $p=$a->db->one('SELECT * FROM cy_payouts WHERE id=?'.$a->db->lock(),[$id]);if(in_array($p['status'],['succeeded','failed'],true)){return;}$w=$a->db->one('SELECT * FROM cy_withdrawals WHERE id=?'.$a->db->lock(),[(int)$p['withdrawal_id']]);
                if($state==='failed'){$a->wallet->adjust((int)$w['user_id'],$w['currency'],(int)$w['amount'],'withdraw:'.$w['id'].':release','Verified failed payout #'.$p['batch_key']);}
                if($state!=='pending'){$a->db->update('cy_withdrawals',(int)$w['id'],['status'=>$state==='succeeded'?'paid':'rejected','note'=>'Verified PayPal payout '.$state.' #'.$p['batch_key'],'updated_at'=>time()]);}
                $a->db->update('cy_payouts',$id,['status'=>$state,'lease_until'=>0,'last_error'=>'','updated_at'=>time()]);$a->activity->audit(0,'payout.'.$state,(string)$id);
            });
        }catch(\Throwable $e){$a->db->execute("UPDATE cy_payouts SET status='uncertain',lease_until=0,last_error=?,updated_at=? WHERE id=? AND status NOT IN ('succeeded','failed')",['The payout outcome is unconfirmed. Reserved funds remain held; no replacement batch is created.',time(),$id]);throw $e;}
        return $a->db->one('SELECT * FROM cy_payouts WHERE id=?',[$id]);
    }
}
