<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Migration020
{
    public static function up(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $db->raw("CREATE TABLE IF NOT EXISTS cy_visual_assets (id $pk, slot VARCHAR(64) NOT NULL UNIQUE, asset_key VARCHAR(40) NOT NULL DEFAULT '', media_id INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, updated_at BIGINT NOT NULL)$tail");
        foreach(['cy_contents'=>['cover_art',"VARCHAR(40) NOT NULL DEFAULT ''"],'cy_navigation'=>['icon',"VARCHAR(40) NOT NULL DEFAULT ''"]] as $table=>[$name,$definition]){
            $columns=$db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM '.$table):$db->all('PRAGMA table_info('.$table.')');$found=false;
            foreach($columns as $column){if(($column['Field']??$column['name'])===$name){$found=true;break;}}
            if(!$found){$db->raw('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition);}
        }
    }
}
