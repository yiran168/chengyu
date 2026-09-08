<?php
// Regressions discovered during the native PHP/SQLite and catalog audit.
test('019 installer paths are portable for root and subdirectory URLs',static function(){
    same('',\Chengyu\Core\WebPath::directory('/install/index.php',2));
    same('/island',\Chengyu\Core\WebPath::directory('/island/install/index.php',2));
    same('/island',\Chengyu\Core\WebPath::directory('\\island\\install\\index.php',2));
    same('',\Chengyu\Core\WebPath::directory('/index.php'));
    reject(static function(){\Chengyu\Core\WebPath::directory('//evil.example/index.php');});
});
test('019 price ordering uses the displayed available SKU price', static function() use ($a,$admin) {
    $one=product('physical','balance','100.00');$two=product('physical','balance','2.00');
    $marker='sort019'.key32();
    foreach([$one,$two] as $id){$a->db->update('cy_contents',$id,['title'=>$marker,'tags'=>$marker]);$a->search->sync($id);}
    $a->inventory->save($admin,['content_id'=>$one,'sku'=>'sort-'.key32(),'name'=>'Affordable edition','price'=>'1.00','inventory'=>10,'active'=>1]);
    $items=$a->content->feed('product',null,['q'=>$marker,'sort'=>'price'])['items'];
    same([$one,$two],array_map('intval',array_column($items,'id')));
});
test('019 importing string false cannot silently enable a layout block', static function() use ($a) {
    reject(static function() use ($a) { $a->layout->validate(json_encode(['format'=>'chengyu-layout','version'=>1,'blocks'=>[['id'=>'invalidBool','type'=>'text','enabled'=>'false']]])); });
});
