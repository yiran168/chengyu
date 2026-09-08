<?php
declare(strict_types=1);
namespace Chengyu\Core;
final class Locale
{
    public const SUPPORTED=['zh-CN','en-US'];
    private static string $context='site';
    public static function context(string $scope): void{Input::choice($scope,['site','admin']);self::$context=$scope;}
    public static function scope(): string{return self::$context;}
    public static function negotiate(string $header,string $fallback='zh-CN'): string
    {
        $best=$fallback;$score=-1.0;
        foreach(array_slice(explode(',',substr($header,0,1024)),0,20) as $part){
            if(!preg_match('/^\s*(en|zh)(?:-[a-z0-9-]+)?\s*(?:;\s*q=(0(?:\.\d{1,3})?|1(?:\.0{1,3})?))?\s*$/iD',$part,$m)){continue;}
            $q=isset($m[2])?(float)$m[2]:1.0;
            if($q>0 && $q>$score){$best=strtolower($m[1])==='en'?'en-US':'zh-CN';$score=$q;}
        }
        return $best;
    }
    public static function current(): string
    {
        $chosen=$_SESSION['locale_'.self::$context]??($_COOKIE['cy_locale_'.self::$context]??($_SESSION['locale']??($_COOKIE['cy_locale']??'')));
        if(in_array($chosen,self::SUPPORTED,true)){return $chosen;}
        $a=$GLOBALS['chengyu']??null;$fallback=$a?(string)$a->settings->get('locale_default','zh-CN'):'zh-CN';
        return $a && $a->settings->get('locale_auto')?self::negotiate((string)($_SERVER['HTTP_ACCEPT_LANGUAGE']??''),$fallback):$fallback;
    }
    public static function select(string $locale,string $base,bool $secure,?string $scope=null): void
    {
        Input::choice($locale,self::SUPPORTED);$scope=$scope??self::$context;Input::choice($scope,['site','admin']);$_SESSION['locale_'.$scope]=$locale;
        setcookie('cy_locale_'.$scope,$locale,['expires'=>time()+31536000,'path'=>$base.'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
    }
    public static function translate(string $key): string
    {
        static $zh=null,$reverse=null;
        if($zh===null){$zh=json_decode((string)file_get_contents(dirname(__DIR__).'/lang/zh-CN.json'),true,32,JSON_THROW_ON_ERROR);$reverse=array_flip($zh);}
        return self::current()==='zh-CN'?($zh[$key]??$key):($reverse[$key]??$key);
    }
}
