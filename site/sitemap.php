<?php
declare(strict_types=1);
define('CY_NO_SESSION', true);
$a = require __DIR__ . '/app/bootstrap.php';
if (!$a->settings->get('sitemap_enabled') || $a->settings->get('maintenance') || $a->settings->get('noindex')) { http_response_code(404); exit; }
header('Content-Type: application/xml; charset=UTF-8');
function sitemap_xml(string $text): string { return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function sitemap_url(string $url, int $updated = 0): void {
    echo '<url><loc>' . sitemap_xml($url) . '</loc>' . ($updated ? '<lastmod>' . gmdate('c', $updated) . '</lastmod>' : '') . '</url>';
}
// Bounded pages avoid holding the complete URL set in memory.
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$feed = $a->content->feed('all', null, ['page' => $page, 'size' => 30]);
echo '<?xml version="1.0" encoding="UTF-8"?>';
if (!isset($_GET['page']) && $feed['total'] > 30) {
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    for ($i = 1; $i <= (int)ceil($feed['total'] / 30); $i++) { echo '<sitemap><loc>' . sitemap_xml($a->config['url'] . '/sitemap.php?page=' . $i) . '</loc></sitemap>'; }
    echo '</sitemapindex>'; exit;
}
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
if ($page === 1) { sitemap_url($a->config['url'] . '/'); }
foreach ($feed['items'] as $item) { sitemap_url($a->config['url'] . substr(item_url($item), strlen($a->basePath)), (int)$item['updated_at']); }
echo '</urlset>';
