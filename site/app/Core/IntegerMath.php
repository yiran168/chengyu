<?php
declare(strict_types=1);
namespace Chengyu\Core;
final class IntegerMath
{
    /** floor(a*b/d), without floating money or overflowing the intermediate product. */
    public static function mulDiv(int $a,int $b,int $d,bool $ceil=false): int
    {
        if($a<0||$b<0||$d<=0||$d>intdiv(PHP_INT_MAX,2)){throw new \InvalidArgumentException('Invalid integer ratio');}
        $q=intdiv($a,$d);$r=$a%$d;$result=0;$remainder=0;
        while($b>0){
            if($b%2){if($result>PHP_INT_MAX-$q){throw new \OverflowException('Integer ratio overflow');}$result+=$q;$remainder+=$r;if($remainder>=$d){if($result===PHP_INT_MAX){throw new \OverflowException('Integer ratio overflow');}$result++;$remainder-=$d;}}
            $b=intdiv($b,2);if(!$b){break;}if($q>intdiv(PHP_INT_MAX-1,2)){throw new \OverflowException('Integer ratio overflow');}$q*=2;$r*=2;if($r>=$d){$q++;$r-=$d;}
        }
        if($ceil&&$remainder){if($result===PHP_INT_MAX){throw new \OverflowException('Integer ratio overflow');}$result++;}return $result;
    }
}
