<?php
declare(strict_types=1);
namespace Chengyu\Services;
use Chengyu\Core\{Input,Problem,Settings};

/** Date boundaries are calculated in the site timezone, identically on MySQL and SQLite. */
final class Archives
{
    public static function interval(string $period): array
    {
        if($period===''){return [];}
        if(!preg_match('/^(19[7-9][0-9]|20[0-9]{2}|2100)(?:-(0[1-9]|1[0-2]))?$/D',$period)){
            throw new Problem('Choose a year or a month between 1970 and 2100.');
        }
        $month=strlen($period)===7;
        $start=new \DateTimeImmutable($period.($month?'-01':'-01-01').' 00:00:00');
        return [$start->getTimestamp(),$start->modify($month?'+1 month':'+1 year')->getTimestamp()];
    }
    public static function page(Content $content,Settings $settings,?array $user,array $input): array
    {
        $settings->requireModule('archives');$settings->requireModule('articles');
        $period=Input::text($input['period']??'',40);self::interval($period);
        $filters=['page'=>Input::integer($input['page']??1,1,100000),'size'=>30,'archive_period'=>$period,'archive_order'=>true,'category'=>Input::integer($input['category']??0)];
        return ['feed'=>$content->feed('article',$user,$filters),'period'=>$period,'category'=>$filters['category']];
    }
}
