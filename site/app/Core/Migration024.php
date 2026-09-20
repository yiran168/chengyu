<?php
declare(strict_types=1);
namespace Chengyu\Core;

final class Migration024
{
    public static function up(Database $db): void
    {
        $rows=$db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM cy_navigation'):$db->all('PRAGMA table_info(cy_navigation)');
        $names=array_map(static function(array $row):string{return $row['Field']??$row['name'];},$rows);
        foreach(['category_id'=>'INTEGER NOT NULL DEFAULT 0','preview_count'=>'INTEGER NOT NULL DEFAULT 0','preview_sort'=>"VARCHAR(20) NOT NULL DEFAULT 'latest'"] as $name=>$type){
            if(!in_array($name,$names,true)){$db->raw('ALTER TABLE cy_navigation ADD COLUMN '.$name.' '.$type);}
        }
    }
}
