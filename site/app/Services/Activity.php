<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database, Crypto, Security, Problem};
final class Activity
{
    private Database $db; private Crypto $crypto;
    public function __construct(Database $db, Crypto $crypto) { $this->db = $db; $this->crypto = $crypto; }
    public function audit(int $actor, string $action, string $target): void
    {
        $this->db->insert('cy_audit', ['actor_id' => $actor, 'action' => $action, 'target' => $target,
            'ip_hash' => $this->crypto->digest(Security::ip()), 'created_at' => time()]);
    }
    public function notify(int $user, string $title, string $body, string $route = ''): void
    {
        $this->db->insert('cy_notifications', ['user_id' => $user, 'title' => $title, 'body' => $body,
            'target_route' => $route, 'read_at' => 0, 'created_at' => time()]);
    }
    public function rate(string $action, string $subject, int $limit, int $seconds): void
    { $this->rateMany([[$action,$subject,$limit,$seconds]]); }

    /** All quotas commit together. Denied requests cannot consume unrelated quotas. */
    public function rateMany(array $rules): void
    {
        if(!$rules || count($rules)>16){throw new \InvalidArgumentException('Invalid rate limit rules.');}
        $buckets=[];$now=time();
        foreach($rules as $rule){
            if(!is_array($rule)||!in_array(count($rule),[4,5],true)){throw new \InvalidArgumentException('Invalid rate limit rule.');}
            [$action,$subject,$limit,$seconds]=$rule;
            if(!is_string($action)||!is_string($subject)||!is_int($limit)||!is_int($seconds)||$limit<1||$limit>1000000||$seconds<1||$seconds>86400){throw new \InvalidArgumentException('Invalid rate limit bounds.');}
            $cooldown=$rule[4]??false;if(!is_bool($cooldown)||($cooldown&&$limit!==1)){throw new \InvalidArgumentException('Invalid cooldown rule.');}
            $key=$this->crypto->digest($action.':'.$subject.':'.($cooldown?'cooldown':(string)intdiv($now,$seconds)));
            if(isset($buckets[$key])){throw new \InvalidArgumentException('Duplicate rate limit bucket.');}
            $buckets[$key]=[$limit,$cooldown?$now+$seconds:(intdiv($now,$seconds)+1)*$seconds,$cooldown];
        }
        ksort($buckets,SORT_STRING); // Stable locking order for concurrent MySQL requests.
        $this->db->transaction(function()use($buckets,$now):void{
            foreach($buckets as $bucket=>[$limit,$expires,$cooldown]){
                if($cooldown){
                    $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE hits=CASE WHEN expires_at<=? THEN 1 ELSE hits+1 END,expires_at=CASE WHEN expires_at<=? THEN VALUES(expires_at) ELSE expires_at END':' ON CONFLICT(bucket) DO UPDATE SET hits=CASE WHEN expires_at<=? THEN 1 ELSE hits+1 END,expires_at=CASE WHEN expires_at<=? THEN excluded.expires_at ELSE expires_at END';
                    $this->db->execute('INSERT INTO cy_rate_limits (bucket,hits,expires_at) VALUES (?,1,?)'.$suffix,[$bucket,$expires,$now,$now]);
                    if((int)$this->db->value('SELECT hits FROM cy_rate_limits WHERE bucket=?',[$bucket])>1){throw new Problem('Too many attempts. Please try again later.',429);}continue;
                }
                $suffix=$this->db->driver()==='mysql'?' ON DUPLICATE KEY UPDATE hits=hits+1':' ON CONFLICT(bucket) DO UPDATE SET hits=hits+1';
                $this->db->execute('INSERT INTO cy_rate_limits (bucket,hits,expires_at) VALUES (?,1,?)'.$suffix,[$bucket,$expires]);
                if((int)$this->db->value('SELECT hits FROM cy_rate_limits WHERE bucket=?',[$bucket])>$limit){throw new Problem('Too many attempts. Please try again later.',429);}
            }
        });
    }
}
