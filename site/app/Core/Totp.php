<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** RFC 6238 / HMAC-SHA1. No remote QR service or runtime package is needed. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public static function encode(string $bytes): string
    {
        $buffer=0; $bits=0; $out='';
        for ($i=0,$length=strlen($bytes);$i<$length;$i++) {
            $buffer=($buffer<<8)|ord($bytes[$i]); $bits+=8;
            while ($bits>=5) { $bits-=5; $out.=self::ALPHABET[($buffer>>$bits)&31]; }
            $buffer &= (1<<$bits)-1;
        }
        if ($bits) { $out.=self::ALPHABET[($buffer<<(5-$bits))&31]; }
        return $out;
    }
    public static function decode(string $secret): string
    {
        if (!preg_match('/^[A-Z2-7]{16,128}$/D',$secret)) { throw new \InvalidArgumentException('Invalid authenticator secret'); }
        $buffer=0; $bits=0; $out='';
        for ($i=0,$length=strlen($secret);$i<$length;$i++) {
            $buffer=($buffer<<5)|(int)strpos(self::ALPHABET,$secret[$i]); $bits+=5;
            if ($bits>=8) { $bits-=8; $out.=chr(($buffer>>$bits)&255); }
            $buffer &= (1<<$bits)-1;
        }
        if ($buffer!==0) { throw new \InvalidArgumentException('Non-canonical authenticator secret'); }
        return $out;
    }
    public static function code(string $secret,int $timestamp,int $digits=6): string
    {
        if ($timestamp<0 || !in_array($digits,[6,8],true)) { throw new \InvalidArgumentException('Invalid authenticator parameters'); }
        $step=intdiv($timestamp,30);
        $counter=pack('N2',intdiv($step,4294967296),$step%4294967296);
        $hash=hash_hmac('sha1',$counter,self::decode($secret),true);
        $offset=ord($hash[19])&15;
        $number=((ord($hash[$offset])&127)<<24)|(ord($hash[$offset+1])<<16)|(ord($hash[$offset+2])<<8)|ord($hash[$offset+3]);
        return str_pad((string)($number%($digits===6?1000000:100000000)),$digits,'0',STR_PAD_LEFT);
    }
    /** Accept one time step of drift, but never reuse an already-consumed step. */
    public static function match(string $secret,string $code,int $timestamp,int $lastStep=-1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D',$code)) { return null; }
        $current=intdiv($timestamp,30); $found=null;
        foreach ([-1,0,1] as $drift) {
            $step=$current+$drift;
            if ($step>=0 && hash_equals(self::code($secret,$step*30),$code) && $step>$lastStep) { $found=$step; }
        }
        return $found;
    }
}
