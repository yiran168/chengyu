<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Deliberately small safe Markdown subset. Raw HTML is always escaped. */
final class Markdown
{
    public static function render(string $source, ?callable $image = null): string
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
                $output .= '<li>' . self::inline($lm[1],$image) . '</li>';
            } elseif (preg_match('/^(#{1,4})\s+(.+)$/', $line, $m)) {
                $level = min(5, strlen($m[1]) + 1);
                $output .= '<h' . $level . '>' . self::inline($m[2],$image) . '</h' . $level . '>';
            } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $output .= '<blockquote>' . self::inline($m[1],$image) . '</blockquote>';
            } elseif (trim($line) === '---') { $output .= '<hr>'; }
            elseif (trim($line) !== '') { $output .= '<p>' . self::inline($line,$image) . '</p>'; }
        }
        if ($code) { $output .= '<pre><code>' . self::escape(implode("\n", $buffer)) . '</code></pre>'; }
        if ($list) { $output .= '</ul>'; }
        return $output;
    }
    private static function tokens(string $text): array
    {
        return preg_split('/(`[^`]*`|!\[[^\]\r\n]{0,200}\]\(media:[1-9][0-9]{0,9}\))/', $text,-1,PREG_SPLIT_DELIM_CAPTURE);
    }
    /** Gallery indexing uses exactly the rendered media syntax, excluding code. */
    public static function imageIds(string $source): array
    {
        $code=false;$ids=[];
        foreach(preg_split('/\r\n|\n|\r/',$source) as $line){
            if(preg_match('/^```/',$line)){$code=!$code;continue;}if($code){continue;}
            foreach(self::tokens($line) as $token){if(preg_match('/^!\[[^\]]*\]\(media:([1-9][0-9]{0,9})\)$/D',$token,$m)){$ids[(int)$m[1]]=(int)$m[1];if(count($ids)>=40){return array_values($ids);}}}
        }
        return array_values($ids);
    }
    private static function inline(string $text, ?callable $image = null): string
    {
        $html='';
        foreach(self::tokens($text) as $token){
            if(substr($token,0,1)==='`' && substr($token,-1)==='`'){$html.='<code>'.self::escape(substr($token,1,-1)).'</code>';}
            elseif(preg_match('/^!\[([^\]]*)\]\(media:([1-9][0-9]{0,9})\)$/D',$token,$m)){$html.=$image?$image((int)$m[2],$m[1]):self::escape($token);}
            else{$html.=self::format($token);}
        }
        return $html;
    }
    private static function format(string $text): string
    {
        $text = self::escape($text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)\)/', static function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) { return $m[1]; }
            return '<a href="' . self::escape($url) . '" target="_blank" rel="nofollow noopener noreferrer">' . $m[1] . '</a>';
        }, $text);
        return $text;
    }
    public static function escape($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
