<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Original code-native symbols shared by renderers, editors and validators. */
final class Icons
{
    public const NAMES=['anime-reading','anime-support','anime-gallery','anime-news','anime-discover','anime-community','anime-shop','anime-member','anime-create','anime-archive','arrow','chevron','search','sun','moon','sparkles','book','layers','message','box','crown','wallet','grid','home','code','leaf','compass','heart','bookmark','eye','clock','check','lock','bell','user','users','edit','settings','logout','download','upload','menu','close','plus','shield','chart','gift','refresh','server','file','palette','star','cat','planet','rocket','flower','cloud','coffee','headphones','music','gamepad','camera','image','film','brush','pen','feather','lightbulb','telescope','mountain','sailboat','ticket','medal','gem','calendar','map','pin','mail','link','folder','key','bolt','ribbon'];
    public const PATHS = [
        'arrow' => '<path d="M4 12h15m-6-6 6 6-6 6"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
        'moon' => '<path d="M21 13A9 9 0 0 1 11 3 9 9 0 1 0 21 13Z"/>',
        'sparkles' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3ZM20 2v4m-2-2h4"/>',
        'book' => '<path d="M3 4h7l2 2 2-2h7v15h-7l-2 2-2-2H3V4Zm9 2v15"/>',
        'layers' => '<path d="m12 3 10 5-10 5L2 8l10-5Zm-9 10 9 5 9-5M3 18l9 5 9-5"/>',
        'message' => '<path d="M21 11a9 9 0 0 1-9 9H3l2-4a9 9 0 1 1 16-5Z"/><path d="M8 10h8m-8 4h5"/>',
        'box' => '<path d="m12 2 9 5v10l-9 5-9-5V7l9-5Zm0 10 9-5M3 7l9 5v10M7.5 4.5l9 5"/>',
        'crown' => '<path d="m2 7 5 4 5-8 5 8 5-4-3 13H5L2 7Zm4 9h12"/>',
        'wallet' => '<path d="M20 7H5a3 3 0 0 1 0-6h14v6M3 4v15a2 2 0 0 0 2 2h16V7m0 5h-6v5h6"/><circle cx="16" cy="14.5" r=".5"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
        'home' => '<path d="m3 10 9-8 9 8v11h-6v-7H9v7H3V10Z"/>',
        'code' => '<path d="m8 5-6 7 6 7m8-14 6 7-6 7m-3-17-2 20"/>',
        'leaf' => '<path d="M20 3C3 1 1 15 8 19c8 5 14-4 12-16ZM4 22 16 8"/>',
        'compass' => '<circle cx="12" cy="12" r="10"/><path d="m16 8-3 5-5 3 3-5 5-3Z"/>',
        'heart' => '<path d="M20 4a5 5 0 0 0-8 2 5 5 0 0 0-8-2c-5 5 2 11 8 16 6-5 13-11 8-16Z"/>',
        'bookmark' => '<path d="M5 3h14v19l-7-5-7 5V3Z"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 3"/>',
        'check' => '<path d="m4 12 5 5L20 5"/>',
        'lock' => '<rect x="4" y="10" width="16" height="12" rx="3"/><path d="M8 10V6a4 4 0 0 1 8 0v4m-4 5v3"/>',
        'bell' => '<path d="M4 17h16l-2-4V8a6 6 0 0 0-12 0v5l-2 4Zm5 3a3 3 0 0 0 6 0"/>',
        'user' => '<circle cx="12" cy="7" r="4"/><path d="M4 22v-3a8 8 0 0 1 16 0v3"/>',
        'users' => '<circle cx="9" cy="7" r="4"/><path d="M1 22v-3a8 8 0 0 1 16 0v3m0-19a4 4 0 0 1 0 8m3 3a7 7 0 0 1 3 6"/>',
        'edit' => '<path d="m15 3 6 6-11 11-7 1 1-7L15 3Zm-1 1 6 6"/>',
        'settings' => '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="8" cy="5" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="19" r="2"/>',
        'logout' => '<path d="M9 3H3v18h6m-1-9h14m-5-5 5 5-5 5"/>',
        'download' => '<path d="M12 2v13m-5-5 5 5 5-5M3 16v6h18v-6"/>',
        'upload' => '<path d="M12 17V4m-5 5 5-5 5 5M3 16v6h18v-6"/>',
        'menu' => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'close' => '<path d="m5 5 14 14M5 19 19 5"/>',
        'plus' => '<path d="M12 3v18M3 12h18"/>',
        'shield' => '<path d="m12 2 9 4v7c0 6-9 10-9 10S3 19 3 13V6l9-4Z"/><path d="m8 12 3 3 6-6"/>',
        'chart' => '<path d="M3 3v18h18M7 16l4-5 4 2 6-8"/>',
        'gift' => '<rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v9h14v-9M12 8v14m0-14C3 8 4 1 8 2c3 0 4 6 4 6Zm0 0c9 0 8-7 4-6-3 0-4 6-4 6Z"/>',
        'refresh' => '<path d="M20 8a9 9 0 0 0-16 0m0 8a9 9 0 0 0 16 0M20 2v6h-6M4 22v-6h6"/>',
        'server' => '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6h1m-1 11h1m8-11h4m-4 11h4"/>',
        'file' => '<path d="M14 2H4v20h16V8l-6-6Zm0 0v6h6M8 13h8m-8 4h6"/>',
        'palette' => '<path d="M12 2A10 10 0 1 0 12 22c5 0-3-7 3-7h3C24 15 21 2 12 2Z"/><circle cx="7" cy="8" r="1"/><circle cx="12" cy="6" r="1"/><circle cx="17" cy="9" r="1"/>',
    
        'star' => '<path d="m12 2 3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1Z"/>',
        'cat' => '<path d="m4 10-1-7 6 4a12 12 0 0 1 6 0l6-4-1 7c5 13-21 13-16 0Z"/><path d="M8 12h.1m8 0h.1m-5 3 1 1 1-1M2 15l4 1m12 0 4-1"/>',
        'planet' => '<circle cx="12" cy="12" r="7"/><ellipse cx="12" cy="12" rx="12" ry="3" transform="rotate(-30 12 12)"/>',
        'rocket' => '<path d="M9 16 8 10c2-5 7-8 13-8 0 6-3 11-8 13l-4 1ZM8 10H4l-2 6 7-1m4 0 1 7 5-3v-6M5 18l-3 4 5-2"/><circle cx="15" cy="8" r="2"/>',
        'flower' => '<path d="M12 8c-7-12-14 4-4 4-12 7 4 14 4 4 7 12 14-4 4-4 12-7-4-14-4-4Z"/><circle cx="12" cy="12" r="3"/>',
        'cloud' => '<path d="M6 19a5 5 0 0 1-1-10 7 7 0 0 1 13-1 6 6 0 0 1 0 11Z"/>',
        'coffee' => '<path d="M3 9h13v8a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Zm13 1h3a3 3 0 0 1 0 6h-3M6 2v3m4-3v3m4-3v3"/>',
        'headphones' => '<path d="M3 15v-3a9 9 0 0 1 18 0v3M3 12h4v9H3Zm14 0h4v9h-4Z"/>',
        'music' => '<path d="M9 17V5l12-3v13M9 9l12-3"/><ellipse cx="5" cy="18" rx="4" ry="3"/><ellipse cx="17" cy="16" rx="4" ry="3"/>',
        'gamepad' => '<path d="M6 6h12l4 12c1 4-4 3-7-1H9c-3 4-8 5-7 1Z"/><path d="M7 9v6m-3-3h6m6-1h.1m3 3h.1"/>',
        'camera' => '<path d="M3 7h4l2-3h6l2 3h4v14H3Z"/><circle cx="12" cy="13" r="4"/>',
        'image' => '<rect x="2" y="3" width="20" height="18" rx="3"/><circle cx="8" cy="9" r="2"/><path d="m3 19 6-5 4 2 4-6 4 8"/>',
        'film' => '<rect x="3" y="2" width="18" height="20" rx="2"/><path d="M7 2v20M17 2v20M3 7h4m-4 5h4m-4 5h4M17 7h4m-4 5h4m-4 5h4"/>',
        'brush' => '<path d="m10 14 9-12 3 3-11 10M10 14c-6-3-5 6-8 7 10 3 13-3 8-7Z"/>',
        'pen' => '<path d="m9 3 12 12-5 6L3 8 9 3Zm-6 5L2 22l14-1M2 22l8-8"/><circle cx="11" cy="13" r="2"/>',
        'feather' => '<path d="M3 22 18 7M5 18C-2 8 17-5 21 4c4 8-7 14-16 14ZM10 13h7M14 9h7"/>',
        'lightbulb' => '<path d="M8 16a8 8 0 1 1 8 0v4H8Zm1 7h6M12 10v10"/>',
        'telescope' => '<path d="m2 11 16-9 4 7-16 7-4-5Zm9 3v4m-5 5 5-5 6 5m-6-5v5"/>',
        'mountain' => '<path d="m2 21 8-18 12 18H2Zm5-11 4 3 2-3M17 13l2-4 4 12"/>',
        'sailboat' => '<path d="M12 2v15H3L12 2Zm3 4 6 11h-6ZM2 20h20l-4 3H6Z"/>',
        'ticket' => '<path d="M3 5h18v5a2 2 0 0 0 0 4v5H3v-5a2 2 0 0 0 0-4V5Zm12 0v3m0 3v2m0 3v3"/>',
        'medal' => '<circle cx="12" cy="15" r="6"/><path d="m5 2 4 8m10-8-4 8M5 2h5l2 5 2-5h5m-7 10v6m-3-3h6"/>',
        'gem' => '<path d="M6 3h12l5 6-11 13L1 9l5-6Zm-5 6h22M8 3 6 9l6 13 6-13-2-6"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M7 2v5m10-5v5M3 10h18M7 14h2m6 0h2m-10 4h2m6 0h2"/>',
        'map' => '<path d="m2 5 7-3 6 3 7-3v17l-7 3-6-3-7 3V5Zm7-3v17m6-14v17"/>',
        'pin' => '<path d="M20 9c0 6-8 13-8 13S4 15 4 9a8 8 0 0 1 16 0Z"/><circle cx="12" cy="9" r="3"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 5 10 8L22 5"/>',
        'link' => '<path d="m10 14 4-4M8 16l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 10a4 4 0 0 0 6 0l5-5a4 4 0 0 0-6-6l-2 2" transform="translate(1 0) scale(.92 1)"/>',
        'folder' => '<path d="M2 5h8l3 3h9v13H2V5Z"/>',
        'key' => '<circle cx="7" cy="8" r="5"/><path d="m11 12 10 10m-5-5 3-3m-7-1 3-3"/>',
        'bolt' => '<path d="m14 1-12 13h9l-1 9L22 9h-9Z"/>',
        'ribbon' => '<path d="m12 9-8-6-2 9 10-3 10 3-2-9-8 6Zm-2 1-4 12 6-3 6 3-4-12"/>',
    ];
    public static function labels(): array
    {
        $out=[];foreach(self::NAMES as $name){$out[$name]=Locale::translate('Icon: '.$name);}return $out;
    }
    public static function svg(string $name,string $class=''): string
    {
        return '<svg class="icon '.Markdown::escape($class).'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.(self::PATHS[$name]??self::PATHS['sparkles']).'</svg>';
    }
}
