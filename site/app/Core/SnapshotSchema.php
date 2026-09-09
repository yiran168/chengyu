<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Maintained with migrations; source-controlled names, never archive-provided SQL. */
final class SnapshotSchema
{
    public const TABLES = ['cy_addresses', 'cy_aftersales', 'cy_announcements', 'cy_audit', 'cy_backup_archives', 'cy_badges', 'cy_bounties', 'cy_categories', 'cy_checkouts', 'cy_circle_members', 'cy_collection_items', 'cy_collections', 'cy_comments', 'cy_contents', 'cy_coupons', 'cy_courses', 'cy_creator_earnings', 'cy_downloads', 'cy_email_challenges', 'cy_entitlements', 'cy_external_search_state', 'cy_follows', 'cy_guest_passes', 'cy_identities', 'cy_invitations', 'cy_layout_drafts', 'cy_layout_history', 'cy_layouts', 'cy_learning_progress', 'cy_ledger', 'cy_lessons', 'cy_links', 'cy_media', 'cy_membership_lots', 'cy_memberships', 'cy_messages', 'cy_navigation', 'cy_notifications', 'cy_orders', 'cy_payment_checks', 'cy_payouts', 'cy_plans', 'cy_poll_choices', 'cy_poll_options', 'cy_poll_votes', 'cy_price_rules', 'cy_promotions', 'cy_rate_limits', 'cy_reactions', 'cy_reading_history', 'cy_receipts', 'cy_recon_batches', 'cy_recon_rows', 'cy_refunds', 'cy_remote_uploads', 'cy_reports', 'cy_resets', 'cy_resource_files', 'cy_resource_reports', 'cy_resource_downloads', 'cy_reviews', 'cy_revisions', 'cy_sales', 'cy_search_outbox', 'cy_search_state', 'cy_search_terms', 'cy_second_factors', 'cy_setting_snapshots', 'cy_settings', 'cy_shipments', 'cy_shipping_templates', 'cy_stock_codes', 'cy_support_replies', 'cy_support_templates', 'cy_support_tickets', 'cy_task_claims', 'cy_tasks', 'cy_threads', 'cy_tracking_events', 'cy_translations', 'cy_upload_parts', 'cy_upload_sessions', 'cy_user_badges', 'cy_users', 'cy_variants', 'cy_vouchers', 'cy_visual_assets', 'cy_withdrawals'];
    // Transient challenges, unfinished uploads, and backups themselves are not recovery data.
    public const OMIT = ['cy_backup_archives','cy_rate_limits','cy_resets','cy_email_challenges','cy_upload_sessions','cy_upload_parts','cy_remote_uploads'];
    public static function tables(): array { return array_values(array_diff(self::TABLES,self::OMIT)); }
    public static function present(Database $db): array
    {
        $rows=$db->driver()==='mysql'?$db->all('SHOW TABLES'):$db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $out=[];foreach($rows as $row){$out[]=(string)reset($row);}sort($out);return $out;
    }
    public static function columns(Database $db,string $table): array
    {
        if(!in_array($table,self::TABLES,true)){throw new Problem('Unsupported snapshot table.');}
        $rows=$db->driver()==='mysql'?$db->all('SHOW COLUMNS FROM `'.$table.'`'):$db->all('PRAGMA table_info('.$table.')');
        return array_map(static function(array $r):string{return (string)($r['Field']??$r['name']);},$rows);
    }
    public static function check(Database $db): void
    {
        $present=self::present($db);$present=array_values(array_filter($present,static function(string $t):bool{return strpos($t,'cy_')===0;}));
        $expected=self::TABLES;sort($expected);sort($present);if($present!==$expected){throw new Problem('Application tables differ from the release. Use the host database backup instead.',409);}
        if($db->driver()==='mysql'){
            $rows=$db->all("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ? ESCAPE '!'",['cy!_%']);
            if(count($rows)!==count($expected)){throw new Problem('Could not verify all backup table engines.');}
            foreach($rows as $row){if(strtoupper((string)$row['ENGINE'])!=='INNODB'){throw new Problem('Consistent backups require InnoDB tables.');}}
        }
    }
}
