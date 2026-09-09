<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Additive and restartable upgrades. Never execute a migration received over HTTP. */
final class Migrations
{
    public const VERSION = 12;
    public static function version(Database $db): int
    {
        return (int)$db->value('SELECT value FROM cy_settings WHERE name=?', ['_schema_version']);
    }
    public static function upgrade(Database $db, string $storage): void
    {
        if (self::version($db) > self::VERSION) { throw new Problem('This database belongs to a newer release. Restore matching application files; do not downgrade in place.',503); }
        if (self::version($db) === self::VERSION) { return; }
        if (!is_dir($storage) && !mkdir($storage, 0750, true)) { throw new \RuntimeException('Cannot create private storage.'); }
        $handle = fopen($storage . '/migration.lock.php', 'c+');
        if (!$handle || !flock($handle, LOCK_EX)) { throw new \RuntimeException('Cannot lock database upgrade.'); }
        try {
            if (self::version($db) > self::VERSION) { throw new Problem('This database belongs to a newer release. Restore matching application files; do not downgrade in place.',503); }
        if (self::version($db) === self::VERSION) { return; }
            ftruncate($handle, 0); fwrite($handle, '<?php http_response_code(404); exit;'); fflush($handle);
            if (self::version($db) < 2) { self::v2($db); }
            if (self::version($db) < 3) { self::v3($db); }
            if (self::version($db) < 4) { self::v4($db); }
            if (self::version($db) < 5) { self::v5($db); }
            if (self::version($db) < 6) { self::v6($db); }
            if (self::version($db) < 7) { self::v7($db); }
            if (self::version($db) < 8) { self::v8($db); }
            if (self::version($db) < 9) { Migration017::up($db); }
            if (self::version($db) < 10) { Migration018::up($db); }
            if (self::version($db) < 11) { Migration019::up($db); }
            if (self::version($db) < 12) { Migration020::up($db); }
            $db->transaction(static function () use ($db): void {
                if ($db->one('SELECT name FROM cy_settings WHERE name=?', ['_schema_version'])) {
                    $db->execute('UPDATE cy_settings SET value=? WHERE name=?', [(string)self::VERSION, '_schema_version']);
                } else { $db->insert('cy_settings', ['name' => '_schema_version', 'value' => (string)self::VERSION]); }
            });
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
    private static function column(Database $db, string $table, string $name, string $definition): void
    {
        $columns = $db->driver() === 'mysql' ? $db->all('SHOW COLUMNS FROM ' . $table) : $db->all('PRAGMA table_info(' . $table . ')');
        foreach ($columns as $column) { if (($column['Field'] ?? $column['name']) === $name) { return; } }
        $db->raw('ALTER TABLE ' . $table . ' ADD COLUMN ' . $name . ' ' . $definition);
    }
    private static function v2(Database $db): void
    {
        $pk = $db->driver() === 'mysql' ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $db->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $text = $db->driver() === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $tables = [
            'cy_variants' => "id $pk, content_id INTEGER NOT NULL, sku VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(100) NOT NULL, price_amount BIGINT NOT NULL, inventory INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_checkouts' => "id $pk, user_id INTEGER NOT NULL, request_key VARCHAR(191) NOT NULL UNIQUE, fingerprint VARCHAR(64) NOT NULL, order_ids TEXT NOT NULL, created_at BIGINT NOT NULL",
            'cy_memberships' => "id $pk, user_id INTEGER NOT NULL, tier INTEGER NOT NULL, until_at BIGINT NOT NULL, UNIQUE(user_id,tier)",
            'cy_revisions' => "id $pk, content_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, snapshot $text NOT NULL, created_at BIGINT NOT NULL",
            'cy_collections' => "id $pk, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_collection_items' => "id $pk, collection_id INTEGER NOT NULL, content_id INTEGER NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, UNIQUE(collection_id,content_id)",
            'cy_links' => "id $pk, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, url VARCHAR(1000) NOT NULL, group_name VARCHAR(100) NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_announcements' => "id $pk, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, route VARCHAR(40) NOT NULL DEFAULT 'home', audience VARCHAR(20) NOT NULL DEFAULT 'public', starts_at BIGINT NOT NULL DEFAULT 0, ends_at BIGINT NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_layouts' => "id $pk, slot VARCHAR(64) NOT NULL UNIQUE, document $text NOT NULL, revision INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, updated_at BIGINT NOT NULL",
            'cy_tasks' => "id $pk, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, metric VARCHAR(30) NOT NULL, target_count INTEGER NOT NULL DEFAULT 1, reward_points INTEGER NOT NULL DEFAULT 0, reward_xp INTEGER NOT NULL DEFAULT 0, period VARCHAR(20) NOT NULL DEFAULT 'once', active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_task_claims' => "id $pk, user_id INTEGER NOT NULL, task_id INTEGER NOT NULL, period_key VARCHAR(20) NOT NULL, points INTEGER NOT NULL, experience INTEGER NOT NULL, created_at BIGINT NOT NULL, UNIQUE(user_id,task_id,period_key)",
            'cy_invitations' => "id $pk, code_hash VARCHAR(64) NOT NULL UNIQUE, label VARCHAR(100) NOT NULL, max_uses INTEGER NOT NULL, uses INTEGER NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, active INTEGER NOT NULL DEFAULT 1, actor_id INTEGER NOT NULL, created_at BIGINT NOT NULL",
            'cy_downloads' => "id $pk, media_id INTEGER NOT NULL, user_id INTEGER NOT NULL, created_at BIGINT NOT NULL",
        ];
        foreach ($tables as $name => $columns) { $db->raw("CREATE TABLE IF NOT EXISTS $name ($columns)$tail"); }
        foreach ([
            ['cy_contents', 'edit_version', 'INTEGER NOT NULL DEFAULT 1'],
            ['cy_contents', 'access_days', 'INTEGER NOT NULL DEFAULT 0'],
            ['cy_contents', 'min_vip_tier', 'INTEGER NOT NULL DEFAULT 1'],
            ['cy_contents', 'access_password', "VARCHAR(255) NOT NULL DEFAULT ''"],
            ['cy_orders', 'variant_id', 'INTEGER NOT NULL DEFAULT 0'],
            ['cy_orders', 'access_until', 'BIGINT NOT NULL DEFAULT 0'],
            ['cy_orders', 'fingerprint', "VARCHAR(64) NOT NULL DEFAULT ''"],
            ['cy_stock_codes', 'variant_id', 'INTEGER NOT NULL DEFAULT 0'],
            ['cy_plans', 'tier', 'INTEGER NOT NULL DEFAULT 1'],
            ['cy_comments', 'pinned', 'INTEGER NOT NULL DEFAULT 0'],
        ] as $column) { self::column($db, $column[0], $column[1], $column[2]); }
        // UTF-8 content and revision snapshots can exceed MySQL TEXT's byte limit.
        // Widen before publishing the migration marker; a partial DDL run is restartable.
        if ($db->driver() === 'mysql') {
            foreach ([['cy_contents','body'],['cy_contents','protected_body'],['cy_contents','delivery_text'],['cy_orders','delivery_cipher']] as $field) {
                $db->raw('ALTER TABLE '.$field[0].' MODIFY '.$field[1].' MEDIUMTEXT NOT NULL');
            }
        }
        // Preserve every existing VIP entitlement as tier 1, including manual grants.
        $db->execute('INSERT INTO cy_memberships (user_id,tier,until_at) SELECT u.id,1,u.vip_until FROM cy_users u WHERE u.vip_until>0 AND NOT EXISTS (SELECT 1 FROM cy_memberships m WHERE m.user_id=u.id AND m.tier=1)');
        foreach ([['cy_variants','cy_variant_content','content_id,active'], ['cy_revisions','cy_revision_content','content_id,id'], ['cy_downloads','cy_download_user','user_id,created_at'], ['cy_collection_items','cy_collection_lookup','collection_id,sort_order'], ['cy_task_claims','cy_task_history','user_id,created_at']] as $index) {
            try { $db->raw('CREATE INDEX ' . $index[1] . ' ON ' . $index[0] . ' (' . $index[2] . ')'); }
            catch (\Throwable $e) { if (strpos($e->getMessage(),'already exists') === false && strpos($e->getMessage(),'Duplicate key name') === false) { throw $e; } }
        }
    }
    private static function v3(Database $db): void
    {
        $pk = $db->driver() === 'mysql' ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $db->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            'cy_email_challenges' => "id $pk, email_hash VARCHAR(64) NOT NULL, purpose VARCHAR(20) NOT NULL, session_hash VARCHAR(64) NOT NULL, user_id INTEGER NOT NULL DEFAULT 0, code_hash VARCHAR(64) NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, state VARCHAR(20) NOT NULL DEFAULT 'pending', expires_at BIGINT NOT NULL, used_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL",
            'cy_threads' => "content_id INTEGER PRIMARY KEY, mode VARCHAR(20) NOT NULL DEFAULT 'discussion', poll_question VARCHAR(255) NOT NULL DEFAULT '', closes_at BIGINT NOT NULL DEFAULT 0, results_rule VARCHAR(20) NOT NULL DEFAULT 'after_vote', accepted_comment_id INTEGER NOT NULL DEFAULT 0",
            'cy_poll_options' => "id $pk, content_id INTEGER NOT NULL, label VARCHAR(200) NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_poll_votes' => "id $pk, content_id INTEGER NOT NULL, user_id INTEGER NOT NULL, option_id INTEGER NOT NULL, created_at BIGINT NOT NULL, UNIQUE(content_id,user_id)",
        ];
        foreach ($tables as $name => $columns) { $db->raw("CREATE TABLE IF NOT EXISTS $name ($columns)$tail"); }
        self::column($db, 'cy_users', 'email_verified_at', 'BIGINT NOT NULL DEFAULT 0');
        foreach ([['cy_email_challenges','cy_email_lookup','email_hash,purpose,created_at'], ['cy_poll_options','cy_poll_content','content_id,sort_order'], ['cy_poll_votes','cy_poll_results','content_id,option_id']] as $index) {
            try { $db->raw('CREATE INDEX ' . $index[1] . ' ON ' . $index[0] . ' (' . $index[2] . ')'); }
            catch (\Throwable $e) { if (strpos($e->getMessage(),'already exists') === false && strpos($e->getMessage(),'Duplicate key name') === false) { throw $e; } }
        }
    }

    private static function v4(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        foreach ([
            'cy_circle_members'=>"id $pk, category_id INTEGER NOT NULL, user_id INTEGER NOT NULL, blocked INTEGER NOT NULL DEFAULT 0, granted_until BIGINT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL, UNIQUE(category_id,user_id)",
            'cy_bounties'=>"id $pk, content_id INTEGER NOT NULL UNIQUE, owner_id INTEGER NOT NULL, amount BIGINT NOT NULL, status VARCHAR(20) NOT NULL, expires_at BIGINT NOT NULL, answer_id INTEGER NOT NULL DEFAULT 0, winner_id INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, settled_at BIGINT NOT NULL DEFAULT 0",
            'cy_reviews'=>"id $pk, content_id INTEGER NOT NULL, user_id INTEGER NOT NULL, order_id INTEGER NOT NULL, rating INTEGER NOT NULL, body TEXT NOT NULL, status VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, UNIQUE(content_id,user_id)",
            'cy_poll_choices'=>"id $pk, vote_id INTEGER NOT NULL, option_id INTEGER NOT NULL, UNIQUE(vote_id,option_id)",
        ] as $table=>$columns) { $db->raw("CREATE TABLE IF NOT EXISTS $table ($columns)$tail"); }
        foreach ([
            'parent_id'=>'INTEGER NOT NULL DEFAULT 0', 'post_level'=>"VARCHAR(20) NOT NULL DEFAULT 'login'",'min_vip_tier'=>'INTEGER NOT NULL DEFAULT 1',
            'is_circle'=>'INTEGER NOT NULL DEFAULT 0','join_currency'=>"VARCHAR(20) NOT NULL DEFAULT 'points'",'join_amount'=>'BIGINT NOT NULL DEFAULT 0',
            'join_days'=>'INTEGER NOT NULL DEFAULT 30','join_open'=>'INTEGER NOT NULL DEFAULT 1'
        ] as $name=>$definition) { self::column($db,'cy_categories',$name,$definition); }
        self::column($db,'cy_threads','max_choices','INTEGER NOT NULL DEFAULT 1');
        self::column($db,'cy_contents','invite_uses','INTEGER NOT NULL DEFAULT 1');
        self::column($db,'cy_contents','invite_days','INTEGER NOT NULL DEFAULT 30');
        // One old single-choice ballot becomes one selected option. Restart-safe after interruption.
        $db->raw('INSERT INTO cy_poll_choices (vote_id,option_id) SELECT v.id,v.option_id FROM cy_poll_votes v WHERE NOT EXISTS (SELECT 1 FROM cy_poll_choices c WHERE c.vote_id=v.id AND c.option_id=v.option_id)');
        foreach ([['cy_reviews','cy_review_public','content_id,status'],['cy_bounties','cy_bounty_due','status,expires_at'],['cy_orders','cy_circle_access','user_id,kind,item_id,status,access_until']] as $index) {
            try { $db->raw('CREATE INDEX '.$index[1].' ON '.$index[0].' ('.$index[2].')'); }
            catch (\Throwable $e) { if (strpos($e->getMessage(),'already exists')===false && strpos($e->getMessage(),'Duplicate key name')===false) { throw $e; } }
        }
    }

    private static function v5(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        foreach (['earnings'=>'BIGINT NOT NULL DEFAULT 0','creator_enabled'=>'INTEGER NOT NULL DEFAULT 0','creator_bps'=>'INTEGER NOT NULL DEFAULT -1','history_enabled'=>'INTEGER NOT NULL DEFAULT 1'] as $n=>$d) { self::column($db,'cy_users',$n,$d); }
        self::column($db,'cy_contents','creator_pricing','INTEGER NOT NULL DEFAULT 0');
        self::column($db,'cy_withdrawals','currency',"VARCHAR(20) NOT NULL DEFAULT 'commission'");
        $db->raw("CREATE TABLE IF NOT EXISTS cy_creator_earnings (id $pk, order_id INTEGER NOT NULL UNIQUE, author_id INTEGER NOT NULL, content_id INTEGER NOT NULL, gross_amount BIGINT NOT NULL, net_amount BIGINT NOT NULL, share_bps INTEGER NOT NULL, share_amount BIGINT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', release_at BIGINT NOT NULL, created_at BIGINT NOT NULL, settled_at BIGINT NOT NULL DEFAULT 0, reversed_at BIGINT NOT NULL DEFAULT 0)$tail");
        $db->raw("CREATE TABLE IF NOT EXISTS cy_reading_history (id $pk, user_id INTEGER NOT NULL, content_id INTEGER NOT NULL, progress INTEGER NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL, UNIQUE(user_id,content_id))$tail");
        foreach ([['cy_creator_earnings','cy_income_author','author_id,status,release_at'],['cy_reading_history','cy_history_user','user_id,updated_at,id'],['cy_contents','cy_resource_link','resource_id,status,publish_at,id']] as $idx) {
            $indexes=$db->driver()==='mysql'?$db->all('SHOW INDEX FROM '.$idx[0]):$db->all('PRAGMA index_list('.$idx[0].')');
            $found=false; foreach($indexes as $entry){if(($entry['Key_name']??$entry['name'])===$idx[1]){$found=true;break;}}
            if(!$found){$db->raw('CREATE INDEX '.$idx[1].' ON '.$idx[0].' ('.$idx[2].')');}
        }
    }


    private static function v6(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $tables=[
            'cy_second_factors'=>"id $pk, user_id INTEGER NOT NULL UNIQUE, secret_cipher TEXT NOT NULL, last_step BIGINT NOT NULL DEFAULT -1, recovery_hashes TEXT NOT NULL, enabled_at BIGINT NOT NULL",
            'cy_price_rules'=>"id $pk, content_id INTEGER NOT NULL UNIQUE, vip1_bps INTEGER NOT NULL DEFAULT 10000, vip2_bps INTEGER NOT NULL DEFAULT 10000, vip3_bps INTEGER NOT NULL DEFAULT 10000, promo_bps INTEGER NOT NULL DEFAULT 10000, starts_at BIGINT NOT NULL DEFAULT 0, ends_at BIGINT NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1, updated_at BIGINT NOT NULL",
        ];
        foreach($tables as $name=>$definition){$db->raw("CREATE TABLE IF NOT EXISTS $name ($definition)$tail");}
        foreach([
            ['cy_coupons','scope_kind',"VARCHAR(20) NOT NULL DEFAULT 'all'"],
            ['cy_coupons','content_id','INTEGER NOT NULL DEFAULT 0'],
            ['cy_coupons','starts_at','BIGINT NOT NULL DEFAULT 0'],
            ['cy_coupons','min_vip_tier','INTEGER NOT NULL DEFAULT 0'],
        ] as $column) {self::column($db,$column[0],$column[1],$column[2]);}
    }
    private static function v7(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $text=$db->driver()==='mysql'?'MEDIUMTEXT':'TEXT';
        foreach([
            'cy_identities'=>"id $pk, user_id INTEGER NOT NULL, provider VARCHAR(20) NOT NULL, app_hash VARCHAR(64) NOT NULL, subject_hash VARCHAR(64) NOT NULL, label VARCHAR(100) NOT NULL, created_at BIGINT NOT NULL, last_used_at BIGINT NOT NULL DEFAULT 0, UNIQUE(provider,app_hash,subject_hash), UNIQUE(user_id,provider,app_hash)",
            'cy_payment_checks'=>"id $pk, order_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, result VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL",
            'cy_setting_snapshots'=>"id $pk, actor_id INTEGER NOT NULL, label VARCHAR(100) NOT NULL, payload $text NOT NULL, created_at BIGINT NOT NULL",
            'cy_badges'=>"id $pk, name VARCHAR(100) NOT NULL, description TEXT NOT NULL, symbol VARCHAR(30) NOT NULL, tone VARCHAR(20) NOT NULL, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_user_badges'=>"id $pk, user_id INTEGER NOT NULL, badge_id INTEGER NOT NULL, granted_by INTEGER NOT NULL, granted_at BIGINT NOT NULL, expires_at BIGINT NOT NULL DEFAULT 0, UNIQUE(user_id,badge_id)"
        ] as $table=>$columns){$db->raw("CREATE TABLE IF NOT EXISTS $table ($columns)$tail");}
    }

    private static function v8(Database $db): void
    {
        $pk=$db->driver()==='mysql'?'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail=$db->driver()==='mysql'?' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci':'';
        $text=$db->driver()==='mysql'?'MEDIUMTEXT':'TEXT';
        $tables=[
            'cy_guest_passes'=>"id $pk,user_id INTEGER NOT NULL UNIQUE,email_cipher TEXT NOT NULL,token_hash VARCHAR(64) NOT NULL UNIQUE,claimed_by INTEGER NOT NULL DEFAULT 0,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL",
            'cy_sales'=>"id $pk,sale_no VARCHAR(32) NOT NULL UNIQUE,user_id INTEGER NOT NULL,request_key VARCHAR(191) NOT NULL UNIQUE,fingerprint VARCHAR(64) NOT NULL,amount BIGINT NOT NULL,currency VARCHAR(3) NOT NULL,gateway VARCHAR(20) NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'pending',remote_id VARCHAR(120) NOT NULL DEFAULT '',trade_no VARCHAR(120) NOT NULL DEFAULT '',checkout_url TEXT NOT NULL,config_cipher $text NOT NULL,metadata $text NOT NULL,attempted_at BIGINT NOT NULL DEFAULT 0,created_at BIGINT NOT NULL,paid_at BIGINT NOT NULL DEFAULT 0,expires_at BIGINT NOT NULL,last_check_at BIGINT NOT NULL DEFAULT 0,last_error VARCHAR(255) NOT NULL DEFAULT ''",
            'cy_receipts'=>"id $pk,receipt_key VARCHAR(191) NOT NULL UNIQUE,sale_id INTEGER NOT NULL UNIQUE,created_at BIGINT NOT NULL",
            'cy_shipping_templates'=>"id $pk,name VARCHAR(100) NOT NULL,document $text NOT NULL,revision INTEGER NOT NULL DEFAULT 1,active INTEGER NOT NULL DEFAULT 1,updated_at BIGINT NOT NULL",
            'cy_promotions'=>"id $pk,name VARCHAR(100) NOT NULL,document $text NOT NULL,revision INTEGER NOT NULL DEFAULT 1,active INTEGER NOT NULL DEFAULT 1,starts_at BIGINT NOT NULL DEFAULT 0,ends_at BIGINT NOT NULL DEFAULT 0,updated_at BIGINT NOT NULL",
            'cy_aftersales'=>"id $pk,order_id INTEGER NOT NULL,user_id INTEGER NOT NULL,kind VARCHAR(20) NOT NULL,state VARCHAR(30) NOT NULL,reason TEXT NOT NULL,evidence_id INTEGER NOT NULL DEFAULT 0,return_tracking TEXT NOT NULL,replacement_tracking TEXT NOT NULL,note TEXT NOT NULL,sellable INTEGER NOT NULL DEFAULT 0,revision INTEGER NOT NULL DEFAULT 1,created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL",
            'cy_refunds'=>"id $pk,refund_no VARCHAR(32) NOT NULL UNIQUE,order_id INTEGER NOT NULL UNIQUE,sale_id INTEGER NOT NULL DEFAULT 0,aftersale_id INTEGER NOT NULL DEFAULT 0,amount BIGINT NOT NULL,status VARCHAR(24) NOT NULL DEFAULT 'queued',remote_id VARCHAR(120) NOT NULL DEFAULT '',attempts INTEGER NOT NULL DEFAULT 0,lease_until BIGINT NOT NULL DEFAULT 0,attempted_at BIGINT NOT NULL DEFAULT 0,last_error VARCHAR(255) NOT NULL DEFAULT '',created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL",
            'cy_membership_lots'=>"id $pk,order_id INTEGER NOT NULL UNIQUE,user_id INTEGER NOT NULL,tier INTEGER NOT NULL,starts_at BIGINT NOT NULL,until_at BIGINT NOT NULL,basis_amount BIGINT NOT NULL,currency VARCHAR(20) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',consumed_by INTEGER NOT NULL DEFAULT 0",
            'cy_payouts'=>"id $pk,withdrawal_id INTEGER NOT NULL UNIQUE,batch_key VARCHAR(32) NOT NULL UNIQUE,status VARCHAR(24) NOT NULL DEFAULT 'queued',remote_id VARCHAR(120) NOT NULL DEFAULT '',config_cipher $text NOT NULL,receiver_cipher TEXT NOT NULL,currency VARCHAR(3) NOT NULL,amount BIGINT NOT NULL,lease_until BIGINT NOT NULL DEFAULT 0,attempted_at BIGINT NOT NULL DEFAULT 0,attempts INTEGER NOT NULL DEFAULT 0,last_error VARCHAR(255) NOT NULL DEFAULT '',created_at BIGINT NOT NULL,updated_at BIGINT NOT NULL",
            'cy_translations'=>"id $pk,content_id INTEGER NOT NULL,locale VARCHAR(10) NOT NULL,title VARCHAR(255) NOT NULL,excerpt TEXT NOT NULL,body $text NOT NULL,source_version INTEGER NOT NULL,revision INTEGER NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'draft',updated_at BIGINT NOT NULL,UNIQUE(content_id,locale)",
            'cy_upload_sessions'=>"id $pk,upload_key VARCHAR(48) NOT NULL UNIQUE,user_id INTEGER NOT NULL,name VARCHAR(255) NOT NULL,total_bytes BIGINT NOT NULL,chunk_bytes INTEGER NOT NULL,chunk_count INTEGER NOT NULL,sha256 VARCHAR(64) NOT NULL DEFAULT '',is_private INTEGER NOT NULL DEFAULT 1,state VARCHAR(20) NOT NULL DEFAULT 'open',media_id INTEGER NOT NULL DEFAULT 0,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL",
            'cy_upload_parts'=>"id $pk,upload_id INTEGER NOT NULL,part_number INTEGER NOT NULL,bytes INTEGER NOT NULL,sha256 VARCHAR(64) NOT NULL,UNIQUE(upload_id,part_number)",
            'cy_remote_uploads'=>"id $pk,user_id INTEGER NOT NULL,upload_key VARCHAR(48) NOT NULL UNIQUE,name VARCHAR(255) NOT NULL,mime VARCHAR(100) NOT NULL,bytes BIGINT NOT NULL,object_key VARCHAR(255) NOT NULL,lease_until BIGINT NOT NULL DEFAULT 0,config_cipher $text NOT NULL,state VARCHAR(20) NOT NULL DEFAULT 'pending',media_id INTEGER NOT NULL DEFAULT 0,created_at BIGINT NOT NULL,expires_at BIGINT NOT NULL",
        ];
        foreach($tables as $name=>$columns){$db->raw("CREATE TABLE IF NOT EXISTS $name ($columns)$tail");}
        foreach([
            ['cy_users','is_guest','INTEGER NOT NULL DEFAULT 0'],
            ['cy_contents','shipping_template_id','INTEGER NOT NULL DEFAULT 0'],
            ['cy_contents','weight_grams','INTEGER NOT NULL DEFAULT 0'],
            ['cy_orders','sale_id','INTEGER NOT NULL DEFAULT 0'],
            ['cy_orders','shipping_amount','BIGINT NOT NULL DEFAULT 0'],
            ['cy_orders','fulfilled_at','BIGINT NOT NULL DEFAULT 0'],
            ['cy_orders','received_at','BIGINT NOT NULL DEFAULT 0'],
            ['cy_orders','money_currency',"VARCHAR(3) NOT NULL DEFAULT 'CNY'"],
            ['cy_media','backend',"VARCHAR(20) NOT NULL DEFAULT 'local'"],
            ['cy_media','remote_cipher',"$text NULL"],
        ] as $c){self::column($db,$c[0],$c[1],$c[2]);}
        foreach([
            ['cy_sales','cy_sale_owner','user_id,id'],['cy_sales','cy_sale_work','status,last_check_at'],
            ['cy_orders','cy_order_sale','sale_id,id'],['cy_aftersales','cy_aftersale_order','order_id,id'],
            ['cy_membership_lots','cy_lot_owner','user_id,status,tier'],['cy_refunds','cy_refund_work','status,lease_until'],
            ['cy_upload_sessions','cy_upload_owner','user_id,state'],['cy_translations','cy_translation_lookup','locale,status,content_id'],
        ] as $idx){try{$db->raw('CREATE INDEX '.$idx[1].' ON '.$idx[0].' ('.$idx[2].')');}catch(\Throwable $e){if(strpos($e->getMessage(),'already exists')===false && strpos($e->getMessage(),'Duplicate key name')===false){throw $e;}}}
    }

}
