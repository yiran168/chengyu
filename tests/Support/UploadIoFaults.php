<?php
declare(strict_types=1);
namespace Chengyu\Services;
// Test-only fault injection. Production packages never load or ship this file.
function uploadIoFault($stream,string $operation): bool {
    if(!isset($GLOBALS['upload_io_fault'])){return false;}
    $fault=&$GLOBALS['upload_io_fault'];$uri=stream_get_meta_data($stream)['uri'];
    if($fault['operation']!==$operation||strpos(str_replace('\\','/',$uri),$fault['path'])===false){return false;}
    return --$fault['after']===0;
}
function fwrite($stream,string $data,?int $length=null) {
    if(uploadIoFault($stream,'write')){return 0;}
    return $length===null?\fwrite($stream,$data):\fwrite($stream,$data,$length);
}
function fflush($stream): bool {return uploadIoFault($stream,'flush')?false:\fflush($stream);}
