<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Settings, Input, Problem};

/** Author revenue is a separate ledger asset, not referral commission. */
final class Creator
{
    private Database $db; private Settings $settings; private Wallet $wallet; private Activity $activity;
    public function __construct(Database $db,Settings $settings,Wallet $wallet,Activity $activity)
    { $this->db=$db; $this->settings=$settings; $this->wallet=$wallet; $this->activity=$activity; }

    /** Called only within the purchase transaction, after referral attribution. */
    public function accrue(array $order,array $item): void
    {
        if (!$this->settings->enabled('creator_income') || $order['currency']!=='balance' || $order['status']!=='paid' || (int)$order['amount']<=0 || (int)$order['user_id']===(int)$item['author_id']) {return;}
        $author=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$item['author_id']]);
        if (!$author || $author['status']!=='active' || !(int)$author['creator_enabled']) {return;}
        $bps=(int)$author['creator_bps']<0?(int)$this->settings->get('creator_share_bps'):(int)$author['creator_bps'];
        $net=max(0,(int)$order['amount']-(int)$order['commission_amount']);
        $share=intdiv($net*$bps,10000); if($share<=0){return;}
        if($this->db->one('SELECT id FROM cy_creator_earnings WHERE order_id=?',[(int)$order['id']])) {return;}
        $this->db->insert('cy_creator_earnings',['order_id'=>(int)$order['id'],'author_id'=>(int)$author['id'],'content_id'=>(int)$item['id'],
            'gross_amount'=>(int)$order['amount'],'net_amount'=>$net,'share_bps'=>$bps,'share_amount'=>$share,'status'=>'pending',
            'release_at'=>time()+(int)$this->settings->get('creator_hold_days')*86400,'created_at'=>time()]);
    }

    /** Deterministic order -> revenue -> author lock order, shared with refund. */
    public function settleOne(int $author,int $income): bool
    {
        $this->settings->requireModule('creator_income');
        return $this->db->transaction(function()use($author,$income):bool {
            $hint=$this->db->one('SELECT order_id FROM cy_creator_earnings WHERE id=? AND author_id=?',[$income,$author]);
            if(!$hint){throw new Problem('Income record not found.',404);}
            $order=$this->db->one('SELECT * FROM cy_orders WHERE id=?'.$this->db->lock(),[(int)$hint['order_id']]);
            $row=$this->db->one('SELECT * FROM cy_creator_earnings WHERE id=?'.$this->db->lock(),[$income]);
            if(!$order || $row['status']!=='pending' || (int)$row['release_at']>time() || $order['status']!=='paid') {return false;}
            $meta=json_decode($order['metadata'],true);
            if(($meta['product_type']??'')==='physical' && $order['fulfillment']!=='received') {return false;}
            $this->wallet->lockActive($author);
            $this->wallet->adjust($author,'earnings',(int)$row['share_amount'],'order:'.$order['id'].':earnings','Creator settlement #'.$order['id']);
            $this->db->update('cy_creator_earnings',$income,['status'=>'settled','settled_at'=>time()]);
            $this->activity->audit($author,'creator.settled',(string)$income); return true;
        });
    }
    public function settle(int $author): int
    {
        $this->settings->requireModule('creator_income'); $count=0;
        // Ineligible disputed / unreceived orders cannot starve newer eligible rows.
        $rows=$this->db->all("SELECT e.id FROM cy_creator_earnings e JOIN cy_orders o ON o.id=e.order_id WHERE e.author_id=? AND e.status='pending' AND e.release_at<=? AND o.status='paid' AND o.fulfillment IN ('fulfilled','received') ORDER BY e.order_id LIMIT 50",[$author,time()]);
        foreach($rows as $row) {if($this->settleOne($author,(int)$row['id'])) {$count++;}}
        return $count;
    }
    /** Runs even after the feature or author approval is disabled. */
    public function reverse(array $order): void
    {
        $row=$this->db->one('SELECT * FROM cy_creator_earnings WHERE order_id=?'.$this->db->lock(),[(int)$order['id']]);
        if(!$row || $row['status']==='reversed') {return;}
        if($row['status']==='settled') {$this->wallet->reverseEarnings((int)$row['author_id'],(int)$row['share_amount'],(int)$order['id']);}
        $this->db->update('cy_creator_earnings',(int)$row['id'],['status'=>'reversed','reversed_at'=>time()]);
    }
    public function convert(int $author,int $amount,string $intent): void
    {
        $this->settings->requireModule('wallet'); $this->settings->requireModule('creator_income');
        if(!$this->settings->get('creator_balance_conversion')) {throw new Problem('Earnings conversion is disabled.',403);}
        Input::integer($amount,1,100000000); Wallet::intent($intent);
        $this->db->transaction(function()use($author,$amount,$intent):void {
            $this->wallet->lockActive($author); $key='creator:'.$author.':'.$intent;
            $this->wallet->adjust($author,'earnings',-$amount,$key.':out','Convert creator earnings');
            $this->wallet->adjust($author,'balance',$amount,$key.':in','Convert creator earnings');
        });
    }
    public function summary(int $author): array
    {
        $out=['pending'=>0,'settled'=>0,'reversed'=>0,'total_orders'=>0];
        foreach($this->db->all('SELECT status,SUM(share_amount) AS amount,COUNT(*) AS count FROM cy_creator_earnings WHERE author_id=? GROUP BY status',[$author]) as $row) {$out[$row['status']]=(int)$row['amount'];$out['total_orders']+=(int)$row['count'];}
        return $out;
    }
    public function page(int $author,int $page): array
    {
        $page=max(1,min(100000,$page));$where=$author?' WHERE e.author_id=?':'';$p=$author?[$author]:[];
        $total=(int)$this->db->value('SELECT COUNT(*) FROM cy_creator_earnings e'.$where,$p);
        $rows=$this->db->all('SELECT e.*,o.order_no,o.title,o.status AS order_status,u.display_name FROM cy_creator_earnings e JOIN cy_orders o ON o.id=e.order_id JOIN cy_users u ON u.id=e.author_id'.$where.' ORDER BY e.id DESC LIMIT 20 OFFSET ?',array_merge($p,[($page-1)*20]));
        return ['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/20))];
    }
}
