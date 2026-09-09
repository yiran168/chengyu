<?php
declare(strict_types=1);
namespace Chengyu\Services;
/** One allowlist for the navigation editor and validator. */
final class Navigation
{
    public static function enabled(\Chengyu\Core\Settings $settings,string $route): bool
    {
        $module=self::MODULES[$route]??null;
        return (!$module || $settings->enabled($module)) && ($route!=='archives' || $settings->enabled('articles'));
    }
    public const ROUTES=['home','articles','archives','forum','shop','courses','learning','support','membership','wallet','rankings','favorites','collections','links','tasks','circles','creator','history','following','badges','external'];
    public const MODULES=['courses'=>'courses','learning'=>'courses','support'=>'support','articles'=>'articles','archives'=>'archives','forum'=>'forum','shop'=>'shop','membership'=>'membership','wallet'=>'wallet','rankings'=>'rankings','favorites'=>'favorites','collections'=>'collections','links'=>'links','tasks'=>'tasks','circles'=>'circles','creator'=>'creator_income','history'=>'reading_history','following'=>'follows','badges'=>'badges'];
}
