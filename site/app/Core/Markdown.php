<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Deliberately small safe Markdown subset. Raw HTML is always escaped. */
final class Markdown
{
    public static function render(string $source): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $source);
        $output = ''; $code = false; $list = false; $buffer = [];
        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                if ($list) { $output .= '</ul>'; $list = false; }
                if ($code) { $output .= '<pre><code>' . self::escape(implode("\n", $buffer)) . '</code></pre>'; $buffer = []; }
                $code = !$code; continue;
            }
            if ($code) { $buffer[] = $line; continue; }
            $isList = preg_match('/^\s*[-*] (.+)$/', $line, $lm);
            if ($list && !$isList) { $output .= '</ul>'; $list = false; }
            if ($isList) {
                if (!$list) { $output .= '<ul>'; $list = true; }
                $output .= '<li>' . self::inline($lm[1]) . '</li>';
            } elseif (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
                $level = min(5, strlen($m[1]) + 1);
                $output .= '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
            } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $output .= '<blockquote>' . self::inline($m[1]) . '</blockquote>';
            } elseif (trim($line) === '---') { $output .= '<hr>'; }
            elseif (trim($line) !== '') { $output .= '<p>' . self::inline($line) . '</p>'; }
        }
        if ($code) { $output .= '<pre><code>' . self::escape(implode("\n", $buffer)) . '</code></pre>'; }
        if ($list) { $output .= '</ul>'; }
        return $output;
    }
    private static function inline(string $text): string
    {
        $text = self::escape($text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) { return $m[1]; }
            return '<a href="' . self::escape($url) . '" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
        }, $text);
        return $text;
    }
    public static function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
