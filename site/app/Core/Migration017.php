<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Additive, idempotent schema shared by MySQL and SQLite; no destructive upgrade. */
final class Migration017
{
    public static function up(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $text=$db->driver()==='mysql'?'MEDIUMTEXT':'TEXT';
        $tables=[
            'cy_search_terms'=>"id $pk, content_id INTEGER NOT NULL, locale VARCHAR(10) NOT NULL, token_hash VARCHAR(64) NOT NULL, weight INTEGER NOT NULL, source_version INTEGER NOT NULL, UNIQUE(content_id,locale,token_hash)",
            'cy_search_state'=>"id INTEGER PRIMARY KEY, cursor_id INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, indexed_at BIGINT NOT NULL DEFAULT 0",
            'cy_layout_history'=>"id $pk, slot VARCHAR(64) NOT NULL, revision INTEGER NOT NULL, document $text NOT NULL, actor_id INTEGER NOT NULL, created_at BIGINT NOT NULL, UNIQUE(slot,revision)",
            'cy_layout_drafts'=>"id $pk, slot VARCHAR(64) NOT NULL UNIQUE, document $text NOT NULL, base_revision INTEGER NOT NULL, draft_revision INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, updated_at BIGINT NOT NULL",
            'cy_courses'=>"id $pk, content_id INTEGER NOT NULL UNIQUE, title VARCHAR(200) NOT NULL, description TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL",
            'cy_lessons'=>"id $pk, course_id INTEGER NOT NULL, title VARCHAR(200) NOT NULL, body $text NOT NULL, media_id INTEGER NOT NULL DEFAULT 0, duration_seconds INTEGER NOT NULL DEFAULT 0, sort_order INTEGER NOT NULL DEFAULT 0, preview INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL",
            'cy_learning_progress'=>"id $pk, user_id INTEGER NOT NULL, lesson_id INTEGER NOT NULL, position_seconds INTEGER NOT NULL DEFAULT 0, completed INTEGER NOT NULL DEFAULT 0, note TEXT NOT NULL, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL, UNIQUE(user_id,lesson_id)",
            'cy_shipments'=>"id $pk, order_id INTEGER NOT NULL UNIQUE, carrier VARCHAR(40) NOT NULL, tracking_cipher TEXT NOT NULL, events_cipher $text NOT NULL, state VARCHAR(30) NOT NULL DEFAULT 'pending', revision INTEGER NOT NULL DEFAULT 1, queried_at BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL",
            'cy_tracking_events'=>"id $pk, shipment_id INTEGER NOT NULL, event_at BIGINT NOT NULL, description TEXT NOT NULL, actor_id INTEGER NOT NULL, created_at BIGINT NOT NULL",
            'cy_recon_batches'=>"id $pk, gateway VARCHAR(30) NOT NULL, file_hash VARCHAR(64) NOT NULL, starts_at BIGINT NOT NULL, ends_at BIGINT NOT NULL, actor_id INTEGER NOT NULL, checked_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, UNIQUE(gateway,file_hash,starts_at,ends_at)",
            'cy_recon_rows'=>"id $pk, batch_id INTEGER NOT NULL, event_type VARCHAR(20) NOT NULL, reference VARCHAR(80) NOT NULL, trade_id VARCHAR(191) NOT NULL, currency VARCHAR(3) NOT NULL, amount_minor BIGINT NOT NULL, external_state VARCHAR(20) NOT NULL, event_at BIGINT NOT NULL, local_id INTEGER NOT NULL DEFAULT 0, outcome VARCHAR(30) NOT NULL, message VARCHAR(255) NOT NULL, UNIQUE(batch_id,event_type,reference)",
            'cy_support_tickets'=>"id $pk, user_id INTEGER NOT NULL, order_id INTEGER NOT NULL DEFAULT 0, subject VARCHAR(160) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', priority VARCHAR(20) NOT NULL DEFAULT 'normal', request_key VARCHAR(64) NOT NULL UNIQUE, revision INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
            'cy_support_replies'=>"id $pk, ticket_id INTEGER NOT NULL, user_id INTEGER NOT NULL, body TEXT NOT NULL, request_key VARCHAR(64) NOT NULL UNIQUE, created_at BIGINT NOT NULL",
            'cy_support_templates'=>"id $pk, title VARCHAR(100) NOT NULL, body TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL",
        ];
        foreach($tables as $table=>$columns){$db->raw("CREATE TABLE IF NOT EXISTS $table ($columns)$tail");}
        foreach([
            ['cy_search_terms','cy_search_lookup','token_hash,locale,content_id,source_version'],
            ['cy_lessons','cy_course_lessons','course_id,active,sort_order,id'],
            ['cy_lessons','cy_lesson_media','media_id,active,course_id'],
            ['cy_learning_progress','cy_learning_user','user_id,updated_at'],
            ['cy_tracking_events','cy_tracking_timeline','shipment_id,event_at,id'],
            ['cy_support_tickets','cy_ticket_user','user_id,status,updated_at'],
            ['cy_support_replies','cy_ticket_replies','ticket_id,id'],
            ['cy_recon_rows','cy_recon_outcome','batch_id,outcome,id'],
        ] as $idx){
            $indexes=$db->driver()==='mysql'?$db->all('SHOW INDEX FROM '.$idx[0]):$db->all('PRAGMA index_list('.$idx[0].')');$exists=false;
            foreach($indexes as $entry){if(($entry['Key_name']??$entry['name'])===$idx[1]){$exists=true;break;}}
            if(!$exists){$db->raw('CREATE INDEX '.$idx[1].' ON '.$idx[0].' ('.$idx[2].')');}
        }
        // Preserve the currently published layout as the first available restore point.
        $db->raw('INSERT INTO cy_layout_history (slot,revision,document,actor_id,created_at) SELECT l.slot,l.revision,l.document,l.actor_id,l.updated_at FROM cy_layouts l WHERE NOT EXISTS (SELECT 1 FROM cy_layout_history h WHERE h.slot=l.slot AND h.revision=l.revision)');
        if(!$db->one('SELECT id FROM cy_search_state WHERE id=1')){$db->insert('cy_search_state',['id'=>1,'cursor_id'=>0,'completed'=>0,'indexed_at'=>0]);}
    }
}
