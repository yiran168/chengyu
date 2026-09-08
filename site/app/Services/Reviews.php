<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Input,Problem};
final class Reviews
{
    private Database $db;private Settings $settings;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Activity $activity){$this->db=$db;$this->settings=$settings;$this->activity=$activity;}
    public function eligible(int $user,int $content): ?array
    {return $this->db->one("SELECT * FROM cy_orders WHERE user_id=? AND item_id=? AND kind='content' AND status IN ('paid','refund_requested') ORDER BY id DESC LIMIT 1",[$user,$content]);}
    public function save(array $actor,int $content,int $rating,string $body,Content $contents): int
    {
        $this->settings->requireModule('reviews');Input::integer($rating,1,5);$body=Input::required($body,2000);
        $this->activity->rate('product-review',(string)$actor['id'],20,3600);
        return $this->db->transaction(function()use($actor,$content,$rating,$body,$contents):int {
            $user=$this->db->one('SELECT * FROM cy_users WHERE id=?'.$this->db->lock(),[(int)$actor['id']]);
            if (!$user || $user['status']!=='active') {throw new Problem('Account unavailable.',403);}
            $item=$contents->get($content,$user);
            if ($item['kind']!=='product') {throw new Problem('Reviews are available for shop products only.');}
            $order=$this->eligible((int)$user['id'],$content);
            if (!$order) {throw new Problem('A completed purchase is required to review this product.',403);}
            // Lock the qualifying order, so a simultaneous refund cannot leave a false verified review.
            $order=$this->db->one('SELECT * FROM cy_orders WHERE id=?'.$this->db->lock(),[(int)$order['id']]);
            if (!in_array($order['status'],['paid','refund_requested'],true)) {throw new Problem('A completed purchase is required to review this product.',403);}
            $old=$this->db->one('SELECT * FROM cy_reviews WHERE content_id=? AND user_id=?',[$content,(int)$user['id']]);
            $data=['order_id'=>(int)$order['id'],'rating'=>$rating,'body'=>$body,'status'=>$this->settings->get('reviews_review')?'pending':'approved','updated_at'=>time()];
            if ($old) {$id=(int)$old['id'];$this->db->update('cy_reviews',$id,$data);}
            else {$id=$this->db->insert('cy_reviews',array_merge($data,['content_id'=>$content,'user_id'=>(int)$user['id'],'created_at'=>time()]));}
            $this->activity->audit((int)$user['id'],'review.saved',(string)$id);return $id;
        });
    }
    public function moderate(int $actor,int $id,string $status): void
    {
        Input::choice($status,['approved','rejected']);
        $this->db->transaction(function()use($actor,$id,$status):void {
            $row=$this->db->one('SELECT * FROM cy_reviews WHERE id=?'.$this->db->lock(),[$id]);
            if (!$row) {throw new Problem('Review not found.',404);}
            $this->db->update('cy_reviews',$id,['status'=>$status,'updated_at'=>time()]);$this->activity->audit($actor,'review.'.$status,(string)$id);
        });
    }
    public function listing(int $content,int $page=1): array
    {
        $where=" FROM cy_reviews r JOIN cy_orders o ON o.id=r.order_id JOIN cy_users u ON u.id=r.user_id WHERE r.content_id=? AND r.status='approved' AND o.kind='content' AND o.item_id=r.content_id AND o.user_id=r.user_id AND o.status IN ('paid','refund_requested') AND u.status='active'";
        $stats=$this->db->one('SELECT COUNT(*) AS total,AVG(r.rating) AS average'.$where,[$content]);
        $page=max(1,min(100000,$page));
        return ['total'=>(int)$stats['total'],'average'=>$stats['average']===null?null:round((float)$stats['average'],1),'page'=>$page,'pages'=>max(1,(int)ceil((int)$stats['total']/10)),
            'items'=>$this->db->all('SELECT r.*,u.display_name,u.avatar_id'.$where.' ORDER BY r.id DESC LIMIT 10 OFFSET ?',[$content,($page-1)*10])];
    }
}
