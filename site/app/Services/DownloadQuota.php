<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Database,Settings,Problem};

/** Call only while holding the user's transaction lock. Files and mirrors share one allowance. */
final class DownloadQuota
{
    public static function check(Database $db,Settings $settings,array $account): void
    {
        $limit=(int)$settings->get((int)$account['vip_until']>time()?'download_vip_limit':'download_daily_limit');
        if(in_array($account['role'],['admin','editor'],true) || $limit<1){return;}
        $since=(int)strtotime(date('Y-m-d'));$uid=(int)$account['id'];
        $count=(int)$db->value('SELECT COUNT(*) FROM cy_downloads WHERE user_id=? AND created_at>=?',[$uid,$since]);
        $count+=(int)$db->value('SELECT COUNT(*) FROM cy_resource_downloads WHERE user_id=? AND created_at>=?',[$uid,$since]);
        if($count>=$limit){throw new Problem('Daily private download limit reached.',429);}
    }
}
