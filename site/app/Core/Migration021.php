<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Migration021
{
    public static function up(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->raw("CREATE TABLE IF NOT EXISTS cy_bulletins (id $pk, title VARCHAR(160) NOT NULL, body TEXT NOT NULL, source_name VARCHAR(120) NOT NULL DEFAULT '', source_url VARCHAR(1000) NOT NULL DEFAULT '', cover_id INTEGER NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'draft', publish_at BIGINT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, request_key VARCHAR(100) NOT NULL UNIQUE, fingerprint VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL)$tail");
        $columns=$db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM cy_contents'):$db->all('PRAGMA table_info(cy_contents)');
        $names=array_map(static function(array $row): string{return $row['Field']??$row['name'];},$columns);
        foreach(['source_name'=>"VARCHAR(120) NOT NULL DEFAULT ''",'source_url'=>"VARCHAR(1000) NOT NULL DEFAULT ''",'copyright_mode'=>"VARCHAR(20) NOT NULL DEFAULT 'default'"] as $name=>$definition){
            if(!in_array($name,$names,true)){$db->raw('ALTER TABLE cy_contents ADD COLUMN '.$name.' '.$definition);}
        }
        try{$db->raw('CREATE INDEX cy_bulletin_feed ON cy_bulletins (status,publish_at,id)');}
        catch(\Throwable $e){if(strpos($e->getMessage(),'already exists')===false && strpos($e->getMessage(),'Duplicate key name')===false){throw $e;}}
    }
}
