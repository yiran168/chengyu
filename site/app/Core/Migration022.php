<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Migration022
{
    public static function up(Database $db): void
    {
        $tables=[
            'cy_navigation'=>['parent_id'=>'INTEGER NOT NULL DEFAULT 0','description'=>"VARCHAR(160) NOT NULL DEFAULT ''",'image_id'=>'INTEGER NOT NULL DEFAULT 0','panel_style'=>"VARCHAR(20) NOT NULL DEFAULT 'dropdown'",'revision'=>'INTEGER NOT NULL DEFAULT 1','request_key'=>'VARCHAR(100) NULL','fingerprint'=>"VARCHAR(64) NOT NULL DEFAULT ''"],
            'cy_contents'=>['image_layout'=>"VARCHAR(20) NOT NULL DEFAULT 'default'",'gallery_ids'=>"VARCHAR(100) NOT NULL DEFAULT '[]'",'body_image_ids'=>"VARCHAR(100) NOT NULL DEFAULT '[]'"],
        ];
        foreach($tables as $table=>$definitions){
            $columns=$db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM '.$table):$db->all('PRAGMA table_info('.$table.')');
            $names=array_map(static function(array $row):string{return $row['Field']??$row['name'];},$columns);
            foreach($definitions as $name=>$definition){if(!in_array($name,$names,true)){$db->raw('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$definition);}}
        }
        foreach(['CREATE UNIQUE INDEX cy_navigation_request ON cy_navigation (request_key)','CREATE INDEX cy_navigation_parent ON cy_navigation (parent_id,sort_order,id)'] as $sql){
            try{$db->raw($sql);}catch(\Throwable $e){if(strpos($e->getMessage(),'already exists')===false && strpos($e->getMessage(),'Duplicate key name')===false){throw $e;}}
        }
    }
}
