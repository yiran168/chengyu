<?php
declare(strict_types=1);
define('CY_NO_SESSION', true);
$a = require __DIR__ . '/app/bootstrap.php';
if (!$a->settings->get('rss_enabled') || $a->settings->get('maintenance')) { http_response_code(404); exit; }
header('Content-Type: application/rss+xml; charset=UTF-8');
function xml_text(string $text): string { return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<rss version="2.0"><channel><title>' . xml_text((string)$a->settings->get('site_name')) . '</title><link>' . xml_text($a->config['url']) . '</link><description>' . xml_text((string)$a->settings->get('site_description')) . '</description><language>zh-CN</language>';
foreach ($a->content->feed('article', null, ['size' => 30])['items'] as $item) {
    // Excerpts only. Never serialize protected_body into a public feed.
    $url = $a->config['url'] . substr(item_url($item), strlen($a->basePath));
    echo '<item><title>' . xml_text($item['title']) . '</title><link>' . xml_text($url) . '</link><guid isPermaLink="true">' . xml_text($url) . '</guid><pubDate>' . gmdate(DATE_RSS, (int)$item['created_at']) . '</pubDate><description>' . xml_text($item['excerpt']) . '</description></item>';
}
echo '</channel></rss>';
