<?php
declare(strict_types=1);
namespace Chengyu\Core;
/** Bounded, sequenced AES-GCM records with an authenticated terminal record. No PHP serialization. */
final class EncryptedArchive
{
    public const MAGIC = "CYBK1\n";
    public const ITERATIONS = 600000;
    public const FRAME_MAX = 4194304;
    public const ARCHIVE_MAX = 536870912;
    private $stream; private string $key; private string $header; private string $previous='';
    private int $sequence=0; private int $bytes=0; private int $maximum; private bool $closed=false;
    public static function password(string $password): string
    {
        if(strlen($password)<16 || strlen($password)>256 || preg_match('/[\x00-\x1F\x7F]/',$password)){throw new Problem('Use a separate backup password of 16 to 256 bytes.');}return $password;
    }
    public function __construct($stream,string $password,int $maximum)
    {
        if(!is_resource($stream)||$maximum<1024||$maximum>self::ARCHIVE_MAX){throw new \InvalidArgumentException('Invalid archive stream or limit.');}
        $this->stream=$stream;$this->maximum=$maximum;$salt=random_bytes(16);
        $json=json_encode(['format'=>1,'iterations'=>self::ITERATIONS,'salt'=>bin2hex($salt)],JSON_THROW_ON_ERROR);
        $this->header=hash('sha256',self::MAGIC.$json,true);
        $this->key=hash_pbkdf2('sha256',self::password($password),$salt,self::ITERATIONS,32,true);
        $this->write(self::MAGIC.$json."\n");
    }
    private function write(string $bytes): void
    {
        if($this->bytes+strlen($bytes)>$this->maximum){throw new Problem('Backup exceeds the configured size limit. Use host backups for larger sites.',413);}
        $offset=0;$n=strlen($bytes);while($offset<$n){$w=fwrite($this->stream,substr($bytes,$offset));if($w===false||$w===0){throw new \RuntimeException('Incomplete archive write.');}$offset+=$w;}$this->bytes+=$n;
    }
    public function add(array $record): void
    {
        if($this->closed){throw new \LogicException('Archive is already closed.');}
        $plain=json_encode($record,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(strlen($plain)>self::FRAME_MAX-28){throw new Problem('A single backup record is too large. Use the host database export.',413);}
        $iv=random_bytes(12);$tag='';$aad=$this->header.pack('N',$this->sequence).$this->previous;
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$this->key,OPENSSL_RAW_DATA,$iv,$tag,$aad,16);
        if($cipher===false||strlen($tag)!==16){throw new \RuntimeException('Backup encryption failed.');}
        $frame=$iv.$tag.$cipher;$this->write(pack('N',strlen($frame)).$frame);$this->previous=$tag;$this->sequence++;
        if(($record['type']??'')==='end'){$this->closed=true;}
    }
    public function complete(): bool{return $this->closed;}
    private static function read($stream,int $count): string
    {
        $out='';while(strlen($out)<$count){$x=fread($stream,$count-strlen($out));if($x===false||$x===''){throw new Problem('Backup is truncated or unreadable.');}$out.=$x;}return $out;
    }
    /** The consumer is called only after each record authenticates. Recovery also verifies a full first pass. */
    public static function consume(string $path,string $password,callable $consumer): void
    {
        self::password($password);$size=@filesize($path);
        if($size===false||$size<64||$size>self::ARCHIVE_MAX+512||is_link($path)){throw new Problem('Backup file is missing, too large, or is a symbolic link.');}
        $h=fopen($path,'rb');if(!$h){throw new Problem('Backup cannot be opened.');}
        try {
            $guard=\Chengyu\Services\Media::GUARD;$start=self::read($h,strlen(self::MAGIC));
            if($start!==self::MAGIC){rewind($h);if(self::read($h,strlen($guard))!==$guard||self::read($h,strlen(self::MAGIC))!==self::MAGIC){throw new Problem('Unknown backup format.');}}
            $line=fgets($h,512);if($line===false||substr($line,-1)!=="\n"){throw new Problem('Invalid backup header.');}$json=substr($line,0,-1);
            try{$meta=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('Invalid backup header.');}
            if(!is_array($meta)||($meta['format']??0)!==1||($meta['iterations']??0)!==self::ITERATIONS||!is_string($meta['salt']??null)||!preg_match('/^[a-f0-9]{32}$/D',$meta['salt'])){throw new Problem('Unsupported backup key parameters.');}
            $key=hash_pbkdf2('sha256',$password,hex2bin($meta['salt']),self::ITERATIONS,32,true);$header=hash('sha256',self::MAGIC.$json,true);$prev='';$seq=0;$ended=false;
            while(!feof($h)){
                $raw=fread($h,4);if($raw===''){break;}if($raw===false||strlen($raw)!==4||$ended){throw new Problem('Backup has an invalid frame or trailing data.');}
                $length=unpack('Nlength',$raw)['length'];if($length<29||$length>self::FRAME_MAX){throw new Problem('Invalid backup frame length.');}
                $frame=self::read($h,$length);$tag=substr($frame,12,16);
                $plain=openssl_decrypt(substr($frame,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($frame,0,12),$tag,$header.pack('N',$seq).$prev);
                if($plain===false){throw new Problem('Wrong backup password or authenticated backup data was modified.');}
                try{$record=json_decode($plain,true,32,JSON_THROW_ON_ERROR);}catch(\Throwable $e){throw new Problem('Invalid backup record.');}
                if(!is_array($record)||!is_string($record['type']??null)){throw new Problem('Invalid backup record.');}
                $consumer($record);$prev=$tag;$seq++;$ended=$record['type']==='end';
            }
            if(!$ended){throw new Problem('Backup has no authenticated completion record.');}
        } finally {fclose($h);}
    }
}
