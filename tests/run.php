<?php
$cases=['pdo.open','sqlite3.open','pdo.parameters','pdo.positional','pdo.transactions','pdo.blob-null','pdo.udf','pdo.collation','pdo.constraint','pdo.readonly','dbal.query','dbal.schema','dbal.savepoint','orm.crud','symfony.kernel','folio.sqlite3','folio.counts','folio.join','folio.json','folio.sort','folio.translations','folio.catalog','folio.schema','pixie.read'];
$results=[];
foreach($cases as $case){
    $p=proc_open([PHP_BINARY,'-d','display_errors=stderr',__DIR__.'/case.php',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    foreach($pipes as $pipe)stream_set_blocking($pipe,false);
    $out='';$err='';$start=microtime(true);$timedOut=false;
    do{$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);$status=proc_get_status($p);if(!$status['running'])break;if(microtime(true)-$start>30){$timedOut=true;proc_terminate($p,9);break;}usleep(10000);}while(true);
    $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);proc_close($p);
    $result=json_decode(trim($out),true)??['case'=>$case,'status'=>$timedOut?'timeout':'process_failure','exit'=>$status['exitcode'],'stdout'=>$out];
    if($err!=='')$result['stderr']=$err;$results[]=$result;
}
echo json_encode(['engine'=>getenv('ENGINE'),'php'=>PHP_VERSION,'zts'=>PHP_ZTS,'sqlite_version'=>SQLite3::version(),'loaded_sqlite_libraries'=>array_values(array_unique(array_filter(array_map(fn($line)=>preg_match('~(/[^ ]*(?:sqlite|turso)[^ ]*\.so[^ ]*)$~',trim($line),$m)?$m[1]:null,explode("\n",file_get_contents('/proc/self/maps')))))),'cases'=>$results],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
