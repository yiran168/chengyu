<?php
test('eight concurrent author settlement requests credit one earnings entry',static function()use($a,$writer,$reader,$tmp){
 $p=product('digital','balance','10.00');$a->db->update('cy_contents',$p,['author_id'=>$writer]);$o=$a->commerce->buy($reader,'content',$p,key32());$id=(int)$a->db->value('SELECT id FROM cy_creator_earnings WHERE order_id=?',[(int)$o['id']]);$a->db->update('cy_creator_earnings',$id,['release_at'=>0]);$before=funds($writer,'earnings');
 $processes=[];for($i=0;$i<8;$i++){$p=proc_open([PHP_BINARY,'-d','ffi.enable=1',__DIR__.'/creator_worker.php',$tmp.'/config.json',(string)$writer,(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);$processes[]=[$p,$pipes];}
 $done=0;foreach($processes as [$p,$pipes]){$out=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($p)!==0){throw new RuntimeException($err);}if($out==='settled'){$done++;}}
 same(1,$done);same($before+800,funds($writer,'earnings'));same(1,(int)$a->db->value('SELECT COUNT(*) FROM cy_ledger WHERE idempotency_key=?',['order:'.$o['id'].':earnings']));
});
