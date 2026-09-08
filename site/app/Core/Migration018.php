<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Restartable v10: preserve shipment IDs while replacing the one-parcel constraint. */
final class Migration018
{
    public static function up(Database $db): void
    {
        $mysql=$db->driver()==='mysql';
        $pk=$mysql?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$mysql?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $tables=[
            'cy_search_outbox'=>"id $pk, content_id INTEGER NOT NULL UNIQUE, generation INTEGER NOT NULL DEFAULT 1, task_uid BIGINT NOT NULL DEFAULT -1, task_generation INTEGER NOT NULL DEFAULT 0, target_hash VARCHAR(64) NOT NULL DEFAULT '', lease_token VARCHAR(64) NOT NULL DEFAULT '', lease_until BIGINT NOT NULL DEFAULT 0, attempts INTEGER NOT NULL DEFAULT 0, next_attempt_at BIGINT NOT NULL DEFAULT 0, last_error VARCHAR(255) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
            'cy_external_search_state'=>"id INTEGER PRIMARY KEY, cursor_id INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0",
            'cy_backup_archives'=>"id $pk, public_id VARCHAR(64) NOT NULL UNIQUE, bytes BIGINT NOT NULL, checksum VARCHAR(64) NOT NULL, actor_id INTEGER NOT NULL, created_at BIGINT NOT NULL",
        ];
        foreach($tables as $table=>$columns){$db->raw("CREATE TABLE IF NOT EXISTS $table ($columns)$tail");}
        self::index($db,'cy_search_outbox','cy_search_outbox_due','next_attempt_at,lease_until,id');
        if(!$db->one('SELECT id FROM cy_external_search_state WHERE id=1')){$db->insert('cy_external_search_state',['id'=>1]);}
        if($mysql){
            self::column($db,'cy_shipments','package_key',"VARCHAR(64) NOT NULL DEFAULT 'primary'");
            self::column($db,'cy_shipments','package_label',"VARCHAR(100) NOT NULL DEFAULT ''");
            self::index($db,'cy_shipments','cy_shipment_order','order_id,id');
            self::index($db,'cy_shipments','cy_shipment_package','order_id,package_key',true);
            $indexes=[];
            foreach($db->all('SHOW INDEX FROM cy_shipments') as $i){$indexes[$i['Key_name']][]=$i;}
            foreach($indexes as $name=>$parts){
                if(count($parts)===1 && !(int)$parts[0]['Non_unique'] && $parts[0]['Column_name']==='order_id'){
                    if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)){throw new \RuntimeException('Unexpected shipment index name.');}
                    $db->raw('ALTER TABLE cy_shipments DROP INDEX `'.$name.'`');
                }
            }
        }elseif(!self::hasColumn($db,'cy_shipments','package_key')){
            // SQLite cannot drop its automatic UNIQUE index. DDL and data move together.
            $db->transaction(static function()use($db,$pk):void{
                $db->raw("CREATE TABLE cy_shipments_v10 (id $pk, order_id INTEGER NOT NULL, carrier VARCHAR(40) NOT NULL, tracking_cipher TEXT NOT NULL, events_cipher TEXT NOT NULL, state VARCHAR(30) NOT NULL DEFAULT 'pending', revision INTEGER NOT NULL DEFAULT 1, queried_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL, package_key VARCHAR(64) NOT NULL DEFAULT 'primary', package_label VARCHAR(100) NOT NULL DEFAULT '', UNIQUE(order_id,package_key))");
                $cols='id,order_id,carrier,tracking_cipher,events_cipher,state,revision,queried_at,updated_at';
                $db->raw('INSERT INTO cy_shipments_v10 ('.$cols.') SELECT '.$cols.' FROM cy_shipments');
                if((int)$db->value('SELECT COUNT(*) FROM cy_shipments')!==(int)$db->value('SELECT COUNT(*) FROM cy_shipments_v10')){throw new \RuntimeException('Shipment migration count mismatch.');}
                $db->raw('DROP TABLE cy_shipments');$db->raw('ALTER TABLE cy_shipments_v10 RENAME TO cy_shipments');
            });
            self::index($db,'cy_shipments','cy_shipment_order','order_id,id');
        }
    }
    private static function hasColumn(Database $db,string $table,string $name): bool
    {
        foreach($db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM '.$table):$db->all('PRAGMA table_info('.$table.')') as $c){if(($c['Field']??$c['name'])===$name){return true;}}return false;
    }
    private static function column(Database $db,string $table,string $name,string $type): void
    {if(!self::hasColumn($db,$table,$name)){$db->raw('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);}}
    private static function index(Database $db,string $table,string $name,string $fields,bool $unique=false): void
    {
        foreach($db->driver()==='mysql'?$db->all('SHOW INDEX FROM '.$table):$db->all('PRAGMA index_list('.$table.')') as $i){if(($i['Key_name']??$i['name'])===$name){return;}}
        $db->raw('CREATE '.($unique?'UNIQUE ':'').'INDEX '.$name.' ON '.$table.' ('.$fields.')');
    }
}
