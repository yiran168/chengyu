<?php
declare(strict_types=1);
namespace Chengyu;
use Chengyu\Core\Database;

final class Schema
{
    public const VERSION = 1;
    public static function create(Database $db): void
    {
        $pk = $db->driver() === 'mysql' ? 'INTEGER NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $db->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            'cy_settings' => '`name` VARCHAR(100) PRIMARY KEY, `value` TEXT NOT NULL',
            'cy_users' => "id $pk, username VARCHAR(40) NOT NULL UNIQUE, email VARCHAR(191) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, display_name VARCHAR(100) NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'user', status VARCHAR(20) NOT NULL DEFAULT 'active', bio TEXT NOT NULL, avatar_id INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0, session_version INTEGER NOT NULL DEFAULT 1, balance BIGINT NOT NULL DEFAULT 0, points BIGINT NOT NULL DEFAULT 0, tokens BIGINT NOT NULL DEFAULT 0, commission BIGINT NOT NULL DEFAULT 0, experience INTEGER NOT NULL DEFAULT 0, vip_until BIGINT NOT NULL DEFAULT 0, referred_by INTEGER NOT NULL DEFAULT 0, last_checkin VARCHAR(10) NOT NULL DEFAULT '', checkin_streak INTEGER NOT NULL DEFAULT 0, total_checkins INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, last_login BIGINT NOT NULL DEFAULT 0",
            'cy_categories' => "id $pk, name VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL, description TEXT NOT NULL, sort_order INTEGER NOT NULL DEFAULT 0, access_level VARCHAR(20) NOT NULL DEFAULT 'public', icon VARCHAR(30) NOT NULL DEFAULT 'layers'",
            'cy_contents' => "id $pk, kind VARCHAR(20) NOT NULL, title VARCHAR(255) NOT NULL, slug VARCHAR(191) NOT NULL UNIQUE, excerpt TEXT NOT NULL, body TEXT NOT NULL, protected_body TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft', category_id INTEGER NOT NULL DEFAULT 0, author_id INTEGER NOT NULL, cover_id INTEGER NOT NULL DEFAULT 0, tags VARCHAR(255) NOT NULL DEFAULT '', price_amount BIGINT NOT NULL DEFAULT 0, price_currency VARCHAR(20) NOT NULL DEFAULT 'balance', access_level VARCHAR(20) NOT NULL DEFAULT 'public', pinned INTEGER NOT NULL DEFAULT 0, featured INTEGER NOT NULL DEFAULT 0, view_count BIGINT NOT NULL DEFAULT 0, resource_id INTEGER NOT NULL DEFAULT 0, product_type VARCHAR(20) NOT NULL DEFAULT 'digital', inventory INTEGER NOT NULL DEFAULT -1, sold_count INTEGER NOT NULL DEFAULT 0, vip_free INTEGER NOT NULL DEFAULT 0, comment_enabled INTEGER NOT NULL DEFAULT 1, delivery_text TEXT NOT NULL, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL, publish_at BIGINT NOT NULL DEFAULT 0",
            'cy_plans' => "id $pk, name VARCHAR(100) NOT NULL, days INTEGER NOT NULL, price_amount BIGINT NOT NULL, price_currency VARCHAR(20) NOT NULL DEFAULT 'balance', description TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1, sort_order INTEGER NOT NULL DEFAULT 0",
            'cy_orders' => "id $pk, order_no VARCHAR(64) NOT NULL UNIQUE, request_key VARCHAR(191) NOT NULL UNIQUE, user_id INTEGER NOT NULL, kind VARCHAR(20) NOT NULL, item_id INTEGER NOT NULL DEFAULT 0, title VARCHAR(255) NOT NULL, amount BIGINT NOT NULL, currency VARCHAR(20) NOT NULL, status VARCHAR(30) NOT NULL DEFAULT 'pending', gateway VARCHAR(20) NOT NULL DEFAULT '', gateway_type VARCHAR(20) NOT NULL DEFAULT '', gateway_trade_no VARCHAR(100) DEFAULT NULL, fulfillment VARCHAR(30) NOT NULL DEFAULT 'pending', delivery_cipher TEXT NOT NULL, address_snapshot TEXT NOT NULL, metadata TEXT NOT NULL, affiliate_id INTEGER NOT NULL DEFAULT 0, commission_amount BIGINT NOT NULL DEFAULT 0, coupon_id INTEGER NOT NULL DEFAULT 0, created_at BIGINT NOT NULL, paid_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL DEFAULT 0, UNIQUE(gateway, gateway_trade_no)",
            'cy_ledger' => "id $pk, user_id INTEGER NOT NULL, currency VARCHAR(20) NOT NULL, delta BIGINT NOT NULL, balance_after BIGINT NOT NULL, reason VARCHAR(255) NOT NULL, idempotency_key VARCHAR(191) NOT NULL UNIQUE, created_at BIGINT NOT NULL",
            'cy_entitlements' => "id $pk, user_id INTEGER NOT NULL, content_id INTEGER NOT NULL, order_id INTEGER NOT NULL, created_at BIGINT NOT NULL, UNIQUE(user_id, content_id)",
            'cy_coupons' => "id $pk, code VARCHAR(64) NOT NULL UNIQUE, mode VARCHAR(20) NOT NULL, value_amount INTEGER NOT NULL, min_amount BIGINT NOT NULL DEFAULT 0, max_uses INTEGER NOT NULL, uses INTEGER NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, active INTEGER NOT NULL DEFAULT 1",
            'cy_vouchers' => "id $pk, code_hash VARCHAR(64) NOT NULL UNIQUE, label VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL, amount BIGINT NOT NULL, redeemed_by INTEGER NOT NULL DEFAULT 0, redeemed_at BIGINT NOT NULL DEFAULT 0, expires_at BIGINT NOT NULL, created_at BIGINT NOT NULL",
            'cy_stock_codes' => "id $pk, content_id INTEGER NOT NULL, secret_cipher TEXT NOT NULL, secret_hash VARCHAR(64) NOT NULL UNIQUE, order_id INTEGER DEFAULT NULL, created_at BIGINT NOT NULL, UNIQUE(order_id)",
            'cy_comments' => "id $pk, content_id INTEGER NOT NULL, user_id INTEGER NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, body TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at BIGINT NOT NULL",
            'cy_reactions' => "id $pk, user_id INTEGER NOT NULL, content_id INTEGER NOT NULL, kind VARCHAR(20) NOT NULL, created_at BIGINT NOT NULL, UNIQUE(user_id, content_id, kind)",
            'cy_follows' => "id $pk, follower_id INTEGER NOT NULL, followed_id INTEGER NOT NULL, created_at BIGINT NOT NULL, UNIQUE(follower_id, followed_id)",
            'cy_messages' => "id $pk, sender_id INTEGER NOT NULL, recipient_id INTEGER NOT NULL, body TEXT NOT NULL, read_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL",
            'cy_notifications' => "id $pk, user_id INTEGER NOT NULL, title VARCHAR(255) NOT NULL, body TEXT NOT NULL, target_route VARCHAR(100) NOT NULL DEFAULT '', read_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL",
            'cy_reports' => "id $pk, user_id INTEGER NOT NULL, content_id INTEGER NOT NULL, reason TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', created_at BIGINT NOT NULL",
            'cy_media' => "id $pk, owner_id INTEGER NOT NULL, name VARCHAR(255) NOT NULL, mime VARCHAR(100) NOT NULL, bytes BIGINT NOT NULL, file_key VARCHAR(80) NOT NULL UNIQUE, is_private INTEGER NOT NULL DEFAULT 1, created_at BIGINT NOT NULL",
            'cy_withdrawals' => "id $pk, user_id INTEGER NOT NULL, amount BIGINT NOT NULL, account_cipher TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pending', note TEXT NOT NULL, request_key VARCHAR(191) NOT NULL UNIQUE, created_at BIGINT NOT NULL, updated_at BIGINT NOT NULL",
            'cy_resets' => "id $pk, user_id INTEGER NOT NULL, token_hash VARCHAR(64) NOT NULL UNIQUE, expires_at BIGINT NOT NULL, used_at BIGINT NOT NULL DEFAULT 0, created_at BIGINT NOT NULL",
            'cy_rate_limits' => 'bucket VARCHAR(191) PRIMARY KEY, hits INTEGER NOT NULL, expires_at BIGINT NOT NULL',
            'cy_audit' => "id $pk, actor_id INTEGER NOT NULL, action VARCHAR(100) NOT NULL, target VARCHAR(191) NOT NULL, ip_hash VARCHAR(64) NOT NULL, created_at BIGINT NOT NULL",
            'cy_navigation' => "id $pk, label VARCHAR(100) NOT NULL, route VARCHAR(100) NOT NULL, url VARCHAR(1000) NOT NULL DEFAULT '', visibility VARCHAR(20) NOT NULL DEFAULT 'public', sort_order INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1",
            'cy_addresses' => "id $pk, user_id INTEGER NOT NULL, recipient VARCHAR(100) NOT NULL, phone VARCHAR(40) NOT NULL, address TEXT NOT NULL, created_at BIGINT NOT NULL",
        ];
        foreach ($tables as $name => $columns) { $db->raw("CREATE TABLE IF NOT EXISTS $name ($columns)$tail"); }
        $indexes = [
            ['cy_contents', 'cy_content_feed', 'kind,status,publish_at,created_at'],
            ['cy_contents', 'cy_content_category', 'category_id,status'],
            ['cy_contents', 'cy_content_author', 'author_id,kind'],
            ['cy_orders', 'cy_order_user', 'user_id,created_at'],
            ['cy_orders', 'cy_order_pending', 'status,expires_at'],
            ['cy_ledger', 'cy_ledger_user', 'user_id,created_at'],
            ['cy_stock_codes', 'cy_codes_available', 'content_id,order_id'],
            ['cy_comments', 'cy_comments_content', 'content_id,status,created_at'],
            ['cy_messages', 'cy_messages_recipient', 'recipient_id,created_at'],
            ['cy_notifications', 'cy_notifications_user', 'user_id,read_at'],
            ['cy_rate_limits', 'cy_rate_expiry', 'expires_at'],
            ['cy_audit', 'cy_audit_time', 'created_at'],
        ];
        foreach ($indexes as [$table, $name, $columns]) {
            try { $db->raw("CREATE INDEX $name ON $table ($columns)"); }
            catch (\Throwable $e) {
                if (strpos($e->getMessage(), 'already exists') === false && strpos($e->getMessage(), 'Duplicate key name') === false) { throw $e; }
            }
        }
    }
}
