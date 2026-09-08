<?php
declare(strict_types=1);
return [
    'default_cover_style'=>['appearance','Default cover artwork','select','anime',['anime','abstract']],
    'brand_art'=>['appearance','Brand mark style','select','anime',['anime','text']],
    'logo_media_id'=>['appearance','Custom public logo image ID','int',0,[],0,2147483647],
    'layout_width'=>['appearance','Page width','select','balanced',['balanced','wide','focused']],
    'layout_style'=>['appearance','Editorial layout style','select','island',['island','classic']],
    'resource_library_enabled'=>['modules','Resource releases and mirrors','bool',true],
    'resource_reports_enabled'=>['modules','Report unavailable resources','bool',true],
];
