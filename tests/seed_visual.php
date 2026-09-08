<?php
if (PHP_SAPI !== 'cli') { exit(1); }
define('CY_BOOT',true);date_default_timezone_set('Asia/Shanghai');
$root=dirname(__DIR__);
require $root.'/site/app/autoload.php';require $root.'/site/app/schema.php';
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
require __DIR__.'/Support/FfiSqlite.php';
Chengyu\Core\Database::useTestFactory(static function(array $c){return new FfiSqlite($c['path']);});
}
$c=json_decode(file_get_contents($argv[1]),true);
$db=new Chengyu\Core\Database($c['database']);Chengyu\Schema::create($db);$a=new Chengyu\App($c);
$admin=$db->insert('cy_users',['username'=>'preview_admin','email'=>'preview@example.test','password_hash'=>password_hash(getenv('CY_ADMIN_PASSWORD'),PASSWORD_DEFAULT),'display_name'=>'站点管理员','role'=>'admin','status'=>'active','bio'=>'让好内容与好想法相遇。','created_at'=>time()]);
Chengyu\Seed::run($a,$admin,true,'澄屿');
$db->execute('DELETE FROM cy_settings WHERE name=?',['hero_art']);
$a->settings->seed(['hero_art'=>'anime','theme'=>'tide','glass'=>'frost','color_mode'=>'light']);
$a->catalog->save($admin,'collections',['name'=>'为创作留一点空间','description'=>'从阅读、灵感到日常创作，将值得留下的内容整理在一起。','content_ids'=>'1,2,3','active'=>1]);
$a->catalog->save($admin,'collections',['name'=>'灵感工具箱','description'=>'好工具与真实交流，让想法向前多走一步。','content_ids'=>'7,8,9','active'=>1]);
$a->catalog->save($admin,'links',['name'=>'PHP','description'=>'查阅 PHP 官方文档与语言手册。','url'=>'https://www.php.net/','group_name'=>'开发资料','active'=>1]);
$a->catalog->save($admin,'announcements',['name'=>'在澄屿，发现一点新的可能','description'=>'精选专题与创作任务已上线','audience'=>'public','route'=>'collections','active'=>1]);
$a->tasks->save($admin,['name'=>'今天，也来打个招呼','description'=>'完成每日签到，让坚持多一点回报。','metric'=>'checkin','period'=>'daily','target_count'=>1,'reward_points'=>10,'reward_xp'=>5,'active'=>1]);
$a->tasks->save($admin,['name'=>'让大家认识你','description'=>'完善显示名称与个人简介，从一句自我介绍开始。','metric'=>'profile','period'=>'once','target_count'=>1,'reward_points'=>20,'reward_xp'=>10,'active'=>1]);
$a->tasks->save($admin,['name'=>'好话题，一起聊','description'=>'今天发表两条通过审核的评论。','metric'=>'comments','period'=>'daily','target_count'=>2,'reward_points'=>15,'reward_xp'=>10,'active'=>1]);
$product=$a->content->save($db->one('SELECT * FROM cy_users WHERE id=?',[$admin]),['kind'=>'product','title'=>'灵感手记·日常创作本','body'=>'多规格商品示例：选择尺寸后使用对应价格与库存。上线前请替换为你的真实商品。','excerpt'=>'多规格商品示例：选择尺寸后使用对应价格与库存。上线前请替换为你的真实商品。','status'=>'published','product_type'=>'physical','price'=>'16.00','price_currency'=>'balance','inventory'=>0,'comment_enabled'=>1],true);
$a->inventory->save($admin,['content_id'=>$product,'sku'=>'NOTE-MINI','name'=>'迷你版 / A6','price'=>'16.00','inventory'=>20,'active'=>1]);
$a->inventory->save($admin,['content_id'=>$product,'sku'=>'NOTE-CLASSIC','name'=>'经典版 / A5','price'=>'24.00','inventory'=>12,'active'=>1]);
$a->layout->save($admin,'articles',json_encode(['format'=>'chengyu-layout','version'=>1,'blocks'=>[
 ['id'=>'editorial_heading','type'=>'heading','title'=>'让每一次阅读，都有所收获','text'=>'记录日常的灵光一现，分享可以带走的好想法。','tone'=>'plain','align'=>'left'],
 ['id'=>'selected_reading','type'=>'collection','title'=>'为创作留一点空间','collection'=>1,'size'=>3,'tone'=>'glass']
 ]],JSON_UNESCAPED_UNICODE),0);
$a->invitations->issue($admin,3,1,time()+86400*30,'社区初见');
$a->wallet->checkin($admin);

$demo=json_decode('{"poll_title": "\u597d\u5185\u5bb9\u4e4b\u5916\uff0c\u4f60\u6700\u671f\u5f85\u600e\u6837\u7684\u793e\u533a\u4f53\u9a8c\uff1f", "poll_body": "## \u4e00\u8d77\u51b3\u5b9a\u4e0b\u4e00\u6b65\n\u597d\u7684\u793e\u533a\u7531\u6bcf\u4e00\u4f4d\u53c2\u4e0e\u8005\u5171\u540c\u5851\u9020\u3002\u5206\u4eab\u4e00\u4e2a\u9009\u62e9\uff0c\u4e5f\u6b22\u8fce\u5728\u8bc4\u8bba\u91cc\u8bf4\u8bf4\u7406\u7531\u3002\n\n## \u5173\u4e8e\u8fd9\u6b21\u6295\u7968\n\u6bcf\u4e2a\u8d26\u53f7\u9650\u6295\u4e00\u7968\u3002\u9009\u62e9\u63d0\u4ea4\u540e\u4e0d\u53ef\u66f4\u6539\uff0c\u7ed3\u679c\u4ec5\u5728\u6295\u7968\u540e\u663e\u793a\u3002\u672c\u9875\u4e3a\u72ec\u7acb\u6f14\u793a\u6570\u636e\u3002", "poll_question": "\u4e0b\u4e00\u6b21\u66f4\u65b0\uff0c\u5148\u8ba9\u54ea\u4ef6\u5c0f\u4e8b\u53d8\u5f97\u66f4\u597d\uff1f", "poll_options": "\u66f4\u987a\u624b\u7684\u9605\u8bfb\u4e0e\u6536\u85cf\n\u66f4\u6709\u6e29\u5ea6\u7684\u521b\u4f5c\u4ea4\u6d41\n\u66f4\u6e05\u6670\u7684\u8d44\u6e90\u5206\u7c7b\n\u66f4\u5b89\u9759\u3001\u7ec6\u817b\u7684\u754c\u9762", "question_title": "\u600e\u6837\u4e3a\u4e00\u4e2a\u5c0f\u578b\u793e\u533a\u5efa\u7acb\u6e05\u6670\u7684\u5185\u5bb9\u5206\u7c7b\uff1f", "question_body": "## \u95ee\u9898\u80cc\u666f\n\u5f53\u5185\u5bb9\u6162\u6162\u79ef\u7d2f\uff0c\u5206\u7c7b\u592a\u5c11\u4e0d\u65b9\u4fbf\u67e5\u627e\uff0c\u5206\u7c7b\u592a\u591a\u53c8\u5bb9\u6613\u8ba9\u4eba\u8ff7\u8def\u3002\n\n## \u60f3\u542c\u542c\u4f60\u7684\u7ecf\u9a8c\n\u4f60\u4f1a\u5982\u4f55\u5b89\u6392\u4e00\u7ea7\u5206\u7c7b\u548c\u4e13\u9898\uff1f\u6b22\u8fce\u63d0\u4f9b\u5177\u4f53\u65b9\u6cd5\u3002", "answer": "\u5148\u56f4\u7ed5\u7528\u6237\u7684\u4e3b\u8981\u4efb\u52a1\u5efa\u7acb 3\u20135 \u4e2a\u4e00\u7ea7\u5206\u7c7b\uff0c\u518d\u7528\u4e13\u9898\u7ec4\u7ec7\u8de8\u5206\u7c7b\u5185\u5bb9\u3002\u6bcf\u4e2a\u6708\u590d\u67e5\u4e00\u6b21\u6ca1\u6709\u5f52\u5c5e\u548c\u91cd\u590d\u5f52\u7c7b\u7684\u5185\u5bb9\u3002\u5206\u7c7b\u540d\u79f0\u6700\u597d\u76f4\u767d\uff0c\u5148\u4fdd\u8bc1\u627e\u5f97\u5230\uff0c\u518d\u8003\u8651\u4e2a\u6027\u5316\u8868\u8fbe\u3002"}',true);
$author=$db->one('SELECT * FROM cy_users WHERE id=?',[$admin]);
$poll=$a->content->save($author,['kind'=>'thread','title'=>$demo['poll_title'],'body'=>$demo['poll_body'],'excerpt'=>'Community / a shared direction','status'=>'published','access_level'=>'public','comment_enabled'=>1,'thread_mode'=>'poll','poll_question'=>$demo['poll_question'],'poll_options'=>$demo['poll_options'],'poll_results'=>'after_vote'],true);
$question=$a->content->save($author,['kind'=>'thread','title'=>$demo['question_title'],'body'=>$demo['question_body'],'excerpt'=>'Community / questions worth asking','status'=>'published','access_level'=>'public','comment_enabled'=>1,'thread_mode'=>'question'],true);
$answer=$db->insert('cy_comments',['content_id'=>$question,'user_id'=>$admin,'body'=>$demo['answer'],'status'=>'approved','created_at'=>time()]);
$a->threads->accept($author,$question,$answer,$a->content);
require __DIR__.'/seed_012.php';
require __DIR__.'/seed_013.php';
require __DIR__.'/seed_014.php';
require __DIR__.'/seed_015.php';
require __DIR__.'/seed_018.php';
if (getenv('CY_TEST_IDS')) { file_put_contents(getenv('CY_TEST_IDS'),json_encode(['poll'=>$poll,'question'=>$question,'answer'=>$answer,'circles'=>$circleIds,'funded'=>$funded,'multi'=>$multi,'review_product'=>$reviewProduct,'pricing_product'=>$pricingProduct,'sku_product'=>$product,'settings_snapshot'=>$settingsSnapshot,'lifetime_plan'=>$lifetimePlan,'parcels018'=>$visual018])); }

file_put_contents($argv[2].'/app/config.php',"<?php\nreturn ".var_export($c,true).";\n");
