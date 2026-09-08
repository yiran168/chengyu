<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Crypto,Input,Problem};
final class Invitations
{
    private Database $db; private Crypto $crypto; private Activity $activity;
    public function __construct(Database $db,Crypto $crypto,Activity $activity) { $this->db=$db;$this->crypto=$crypto;$this->activity=$activity; }
    public function issue(int $actor, int $count, int $uses, int $expires, string $label): array
    {
        Input::integer($count,1,100);Input::integer($uses,1,1000);$label=Input::text($label,100);
        if ($expires<=time() || $expires>time()+3660*86400) { throw new Problem('Invalid invitation expiry.'); }
        return $this->db->transaction(function()use($actor,$count,$uses,$expires,$label):array {
            $codes=[];
            for($i=0;$i<$count;$i++) {
                $code=strtoupper(bin2hex(random_bytes(12)));
                $this->db->insert('cy_invitations',['code_hash'=>$this->crypto->digest('invite:'.$code),'label'=>$label,'max_uses'=>$uses,'expires_at'=>$expires,'actor_id'=>$actor,'created_at'=>time()]);$codes[]=$code;
            }
            $this->activity->audit($actor,'invitations.issued',(string)$count);return $codes;
        });
    }
    /** Called within account creation so a failed registration never consumes a use. */
    public function consume(string $code): void
    {
        $code=strtoupper(Input::required($code,100));
        $row=$this->db->one('SELECT * FROM cy_invitations WHERE code_hash=?'.$this->db->lock(),[$this->crypto->digest('invite:'.$code)]);
        if (!$row || !(int)$row['active'] || (int)$row['expires_at']<=time() || (int)$row['uses']>=(int)$row['max_uses']) { throw new Problem('Invitation code is invalid, expired or used.'); }
        $this->db->execute('UPDATE cy_invitations SET uses=uses+1 WHERE id=?',[(int)$row['id']]);
    }
    public function disable(int $actor,int $id):void { $this->db->update('cy_invitations',$id,['active'=>0]);$this->activity->audit($actor,'invitations.disabled',(string)$id); }
}
