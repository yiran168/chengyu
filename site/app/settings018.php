<?php
declare(strict_types=1);
return [
    'search_engine'=>['discovery','Search engine','select','portable',['portable','legacy','meilisearch']],
    'meili_endpoint'=>['discovery','Meilisearch HTTPS endpoint','url',''],
    'meili_index'=>['discovery','Dedicated Meilisearch index (cy_ prefix)','text','cy_public'],
    'meili_key'=>['discovery','Meilisearch server API key','secret',''],
    'meili_consent'=>['discovery','Allow public metadata and search queries to be sent to Meilisearch','bool',false],
    'meili_jobs'=>['discovery','Process search synchronization in scheduled jobs','bool',false],
    'verification_ip_10m'=>['security','Email deliveries per IP / 10 minutes','int',3,[],1,1000],
    'verification_ip_hour'=>['security','Email deliveries per IP / hour','int',30,[],1,10000],
    'verification_ip_day'=>['security','Email deliveries per IP / day','int',150,[],1,100000],
    'verification_recipient_10m'=>['security','Email deliveries per recipient / 10 minutes','int',3,[],1,100],
    'mail_limit'=>['security','Email deliveries per recipient / hour','int',3,[],1,100],
    'verification_recipient_day'=>['security','Email deliveries per recipient / day','int',9,[],1,1000],
    'verification_cooldown'=>['security','Email recipient cooldown (seconds)','int',60,[],30,600],
    'recovery_hold'=>['security','Hold background jobs until recovered transactions are reviewed','bool',false],
    'backup_max_mb'=>['security','Maximum encrypted backup size (MiB)','int',64,[],8,512],
    'backup_retention'=>['security','Maximum retained local backup archives','int',3,[],1,10],
];
