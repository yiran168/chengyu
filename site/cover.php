<?php
declare(strict_types=1);
// A unique, code-native editorial placeholder. No content data or external resources.
$raw=$_GET['id']??'0';
if(!is_string($raw) || !preg_match('/^[0-9]{1,10}$/D',$raw)){http_response_code(400);exit;}
$id=(int)$raw;$hash=hash('sha256',(string)$id);$hue=hexdec(substr($hash,0,4))%360;
header('Content-Type: image/svg+xml; charset=utf-8');header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
header('Cache-Control: public, max-age=86400');
$x=220+hexdec(substr($hash,4,2));$y=80+hexdec(substr($hash,6,2));
echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 960 640"><defs><linearGradient id="g" x2="1" y2="1"><stop stop-color="hsl('.$hue.',50%,94%)"/><stop offset="1" stop-color="hsl('.(($hue+45)%360).',55%,80%)"/></linearGradient></defs><rect width="960" height="640" fill="url(#g)"/><circle cx="'.$x.'" cy="'.$y.'" r="180" fill="white" opacity=".3"/><path d="M0 500Q350 220 960 520V640H0Z" fill="white" opacity=".35"/><text x="72" y="455" font-family="sans-serif" font-size="22" letter-spacing="8" fill="hsl('.$hue.',25%,30%)">CHENGYU</text><text x="72" y="535" font-family="sans-serif" font-size="62" fill="hsl('.$hue.',25%,30%)">'.str_pad((string)$id,6,'0',STR_PAD_LEFT).'</text></svg>';
