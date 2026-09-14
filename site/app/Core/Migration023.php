<?php
declare(strict_types=1);
namespace Chengyu\Core;
final class Migration023
{
    public static function up(Database $db): void
    {
        foreach(['CREATE INDEX cy_media_owner_catalog ON cy_media (owner_id,is_private,id)','CREATE INDEX cy_media_public_catalog ON cy_media (is_private,id)'] as $sql){
            try{$db->raw($sql);}catch(\Throwable $e){if(strpos($e->getMessage(),'already exists')===false && strpos($e->getMessage(),'Duplicate key name')===false){throw $e;}}
        }
    }
}
