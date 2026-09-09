<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Read-only host constraints. Values come from PHP, never from a provider preset. */
final class RuntimeLimits
{
    public static function bytes(string $value): ?int
    {
        $value=trim($value);if($value==='-1'){return -1;}
        if(!preg_match('/^([0-9]+)\s*([KMG]?)$/iD',$value,$m)){return null;}
        $digits=ltrim($m[1],'0');$maximum=(string)PHP_INT_MAX;
        if(strlen($digits)>strlen($maximum) || (strlen($digits)===strlen($maximum) && strcmp($digits,$maximum)>0)){return PHP_INT_MAX;}
        $number=(int)$digits;$factor=[''=>1,'K'=>1024,'M'=>1048576,'G'=>1073741824][strtoupper($m[2])];
        return $number>intdiv(PHP_INT_MAX,$factor)?PHP_INT_MAX:$number*$factor;
    }
    public static function inspect(?array $values=null): array
    {
        if($values===null){$values=[];foreach(['memory_limit','post_max_size','upload_max_filesize','max_execution_time','max_input_time','file_uploads','max_input_vars'] as $key){$values[$key]=(string)ini_get($key);}}
        $checks=[];$add=static function(string $name,string $state,string $detail)use(&$checks):void{$checks[]=['check'=>$name,'state'=>$state,'detail'=>$detail];};
        foreach(['memory_limit'=>67108864,'post_max_size'=>393216,'upload_max_filesize'=>262144] as $key=>$minimum){
            $raw=(string)($values[$key]??'');$bytes=self::bytes($raw);
            $unlimited=($key==='memory_limit' && $bytes===-1) || ($key==='post_max_size' && $bytes===0);
            $add($key,$bytes===null?'manual':($unlimited || $bytes>=$minimum?'pass':'warning'),$raw===''?'Not exposed by PHP':$raw);
        }
        $uploads=!in_array(strtolower(trim((string)($values['file_uploads']??''))),['','0','off','false','no'],true);
        $add('file_uploads',$uploads?'pass':'warning',$uploads?'enabled':'disabled');
        foreach(['max_execution_time'=>20,'max_input_time'=>20,'max_input_vars'=>1000] as $key=>$recommended){
            $raw=(string)($values[$key]??'');$number=filter_var($raw,FILTER_VALIDATE_INT);
            if($key==='max_input_time' && $number===-1){$number=filter_var($values['max_execution_time']??'',FILTER_VALIDATE_INT);$raw.=' (max_execution_time='.(string)($values['max_execution_time']??'').')';}
            $unlimited=in_array($key,['max_input_time','max_execution_time'],true) && $number===0;
            $add($key,$number===false?'manual':($unlimited || $number>=$recommended?'pass':'warning'),$raw===''?'Not exposed by PHP':$raw);
        }
        return $checks;
    }
}
