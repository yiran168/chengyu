<?php
declare(strict_types=1);
return [
    'bulletins_enabled'=>['modules','News bulletins','bool',true],
    'bulletins_sidebar'=>['layout','Show bulletin sidebar','bool',true],
    'article_summary'=>['editorial','Show article summary','bool',true],
    'article_cover'=>['editorial','Show configured article cover','bool',true],
    'article_author_card'=>['editorial','Show article author card','bool',true],
    'article_neighbors'=>['editorial','Show adjacent articles','bool',true],
    'article_related'=>['editorial','Show related articles','bool',true],
    'article_list_layout'=>['editorial','Article list layout','select','list',['list','grid','magazine']],
    'article_thumbnail'=>['editorial','Article thumbnail position','select','left',['left','right','text']],
    'copyright_default'=>['editorial','Default copyright notice','textarea',''],
    'copyright_original'=>['editorial','Original work notice','textarea','本文由 {author} 发布于 {site}，未经作者许可，请勿转载。'],
    'copyright_permission'=>['editorial','Permission notice','textarea','本文经授权发布，权利归原作者所有。再次使用请联系权利人。'],
    'copyright_reprint'=>['editorial','Reprint notice','textarea','本文转载自注明的来源，权利归原作者所有。如需使用，请联系原作者。'],
];
