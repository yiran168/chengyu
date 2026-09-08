<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Migration019
{
    public static function up(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $tables=[
            'cy_resource_files'=>"id $pk, content_id INTEGER NOT NULL, label VARCHAR(120) NOT NULL, release_version VARCHAR(50) NOT NULL DEFAULT '', notes TEXT NOT NULL, media_id INTEGER NOT NULL DEFAULT 0, mirror_cipher TEXT NOT NULL, code_cipher TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
            'cy_resource_reports'=>"id $pk, resource_id INTEGER NOT NULL, user_id INTEGER NOT NULL, reason VARCHAR(30) NOT NULL, body TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', resolution TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL, created_at BIGINT NOT NULL, UNIQUE(resource_id,user_id)",
            'cy_resource_downloads'=>"id $pk, resource_id INTEGER NOT NULL, user_id INTEGER NOT NULL, day_key VARCHAR(10) NOT NULL, created_at BIGINT NOT NULL, UNIQUE(resource_id,user_id,day_key)",
        ];
        foreach($tables as $name=>$columns){$db->raw("CREATE TABLE IF NOT EXISTS $name ($columns)$tail");}
        foreach(['cy_resource_files'=>['cy_resource_content','content_id,active,sort_order,id'],'cy_resource_reports'=>['cy_resource_report_status','status,updated_at'],'cy_resource_downloads'=>['cy_resource_download_user','user_id,created_at']] as $table=>[$name,$fields]){
            $indexes=$db->driver()==='mysql'?$db->all('SHOW INDEX FROM '.$table):$db->all('PRAGMA index_list('.$table.')');$found=false;
            foreach($indexes as $index){if(($index['Key_name']??$index['name'])===$name){$found=true;break;}}
            if(!$found){$db->raw('CREATE INDEX '.$name.' ON '.$table.' ('.$fields.')');}
        }
    }
}
