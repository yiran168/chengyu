<?php
declare(strict_types=1);
return [
    'search_engine'=>['discovery','Search engine','select','portable',['portable','legacy']],
    'courses_enabled'=>['modules','Learning library','bool',true],
    'support_enabled'=>['modules','Support desk','bool',true],
    'tracking_enabled'=>['commerce','Shipment tracking','bool',true],
    'tracking_provider'=>['commerce','Tracking provider','select','manual',['manual','kuaidi100']],
    'tracking_customer'=>['commerce','Kuaidi100 customer ID','text',''],
    'tracking_key'=>['commerce','Kuaidi100 API key','secret',''],
    'tracking_interval'=>['commerce','Tracking refresh interval (seconds)','int',900,[],300,86400],
    'alipay_direct_mode'=>['direct_payments','Alipay checkout experience','select','wap',['wap','page']],
];
