<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Crypto,Input,Problem};

/** Encrypted, bounded settings snapshots. Not a database or media backup. */
final class Configuration
{
    private Database $db;private Settings $settings;private Crypto $crypto;private Activity $activity;
    public function __construct(Database $db,Settings $settings,Crypto $crypto,Activity $activity)
    {$this->db=$db;$this->settings=$settings;$this->crypto=$crypto;$this->activity=$activity;}
    private function admin(int $actor): void
    {if(!$this->db->one("SELECT id FROM cy_users WHERE id=? AND role='admin' AND status='active'",[$actor])){throw new Problem('Access denied.',403);}}
    private function values(): array
    {
        $current=new Settings($this->db,$this->crypto);$values=[];foreach($current->schema() as $k=>$f){$values[$k]=$current->get($k);}ksort($values);return $values;
    }
    public function revision(): string {return $this->crypto->digest(json_encode($this->values(),JSON_THROW_ON_ERROR));}
    public function capture(int $actor,string $label): int
    {
        $this->admin($actor);$label=Input::required($label,100);
        return $this->store($actor,$label,$this->crypto->seal(json_encode(['format'=>'chengyu.settings.v1','version'=>\Chengyu\App::VERSION,'settings'=>$this->values()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
    }
    private function store(int $actor,string $label,string $payload): int
    {
        return $this->db->transaction(function()use($actor,$label,$payload):int{
            $this->db->one('SELECT name FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);$this->admin($actor);
            $id=$this->db->insert('cy_setting_snapshots',['actor_id'=>$actor,'label'=>$label,'payload'=>$payload,'created_at'=>time()]);
            foreach($this->db->all('SELECT id FROM cy_setting_snapshots ORDER BY id DESC LIMIT 1000 OFFSET 30') as $old){$this->db->execute('DELETE FROM cy_setting_snapshots WHERE id=?',[(int)$old['id']]);}
            $this->activity->audit($actor,'settings.snapshot',(string)$id);return $id;
        });
    }
    private function decode(string $payload): array
    {
        if(strlen($payload)>400000){throw new Problem('The settings archive is too large.');}
        try{$data=json_decode($this->crypto->open($payload),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('This settings archive is damaged or belongs to a different site key.');}
        if(!is_array($data) || ($data['format']??'')!=='chengyu.settings.v1' || !is_array($data['settings']??null) || count($data['settings'])>1000){throw new Problem('Invalid settings archive.');}
        if(array_diff_key($data['settings'],$this->settings->schema())){throw new Problem('The archive contains settings from an unsupported version.');}
        return $data['settings'];
    }
    public function import(int $actor,string $payload,string $label): int
    {$this->admin($actor);$payload=trim($payload);$this->decode($payload);return $this->store($actor,Input::required($label,100),$payload);}
    public function export(int $actor,int $id): string
    {$this->admin($actor);$row=$this->db->one('SELECT payload FROM cy_setting_snapshots WHERE id=?',[$id]);if(!$row){throw new Problem('Snapshot not found.',404);}return $row['payload'];}
    public function preview(int $actor,int $id): array
    {
        $stored=$this->decode($this->export($actor,$id));$current=$this->values();$diff=[];
        foreach($stored as $key=>$value){if($current[$key]!==$value){$secret=$this->settings->schema()[$key][2]==='secret';$diff[]=['key'=>$key,'label'=>$this->settings->schema()[$key][1],'before'=>$secret?'[redacted]':$current[$key],'after'=>$secret?'[redacted]':$value];}}
        return ['revision'=>$this->revision(),'changes'=>$diff];
    }
    public function restore(int $actor,int $id,string $revision): void
    {
        $this->admin($actor);$stored=$this->decode($this->export($actor,$id));
        $this->db->transaction(function()use($actor,$id,$revision,$stored):void{
            // All settings writers take this same row lock before changing values.
            $this->db->one('SELECT name FROM cy_settings WHERE name=?'.$this->db->lock(),['_schema_version']);
            if(!hash_equals($this->revision(),$revision)){throw new Problem('Settings changed after preview. Review the differences again.',409);}
            $this->capture($actor,'Before restore #'.$id);$working=new Settings($this->db,$this->crypto);
            foreach(array_unique(array_column($working->schema(),0)) as $group){
                $input=[];foreach($working->schema() as $key=>$field){if($field[0]!==$group){continue;}$value=array_key_exists($key,$stored)?$stored[$key]:$working->get($key);$input[$key]=$field[2]==='bool'?($value?'1':'0'):$value;if($field[2]==='secret' && $value===''){$input['clear_'.$key]='1';}}
                $working->saveGroup($group,$input);
            }$this->activity->audit($actor,'settings.restored',(string)$id);
        });$this->settings->reload();
    }
    public function listing(int $actor): array
    {$this->admin($actor);return $this->db->all('SELECT id,actor_id,label,created_at FROM cy_setting_snapshots ORDER BY id DESC LIMIT 30');}
}
