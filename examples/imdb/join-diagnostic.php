<?php
// Read-only SQL against already-built derivatives; keep the original benchmark unchanged.
declare(strict_types=1);
require __DIR__.'/../native/vendor/autoload.php';
require __DIR__.'/../native/NativeConnection.php';
require __DIR__.'/../native/Driver.php';
$run=$argv[1] ?? throw new RuntimeException('Supply completed run directory');
$source=getenv('TURSO_SOURCE') ?: throw new RuntimeException('Set TURSO_SOURCE');
$library=getenv('TURSO_LIBRARY') ?: throw new RuntimeException('Set TURSO_LIBRARY');
$results=[];$reference=null;
foreach (['sqlite','turso'] as $engine) {
 $db=Doctrine\DBAL\DriverManager::getConnection($engine==='sqlite' ? ['driver'=>'pdo_sqlite','path'=>"$run/test.sqlite"] : ['driverClass'=>Survos\TursoPrototype\Driver::class,'path'=>"$run/test.turso",'library'=>$library,'header'=>"$source/sdk-kit/turso.h"]);
 foreach (['joined_id'=>'t.id','indexed_id'=>'r.title_id'] as $variant=>$order) {
  $sql="SELECT t.id,t.title,r.votes FROM ratings r JOIN titles t ON t.id=r.title_id WHERE r.votes>=10000 ORDER BY r.votes DESC,$order LIMIT 100";
  $plan=$db->fetchAllNumeric('EXPLAIN QUERY PLAN '.$sql);$ms=[];
  for($i=0;$i<5;$i++) { $start=hrtime(true);$rows=$db->fetchAllNumeric($sql);$ms[]=(hrtime(true)-$start)/1e6; }
  $hash=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
  if($reference!==null && $reference!==$hash) throw new RuntimeException('Diagnostic results differ');
  $reference=$hash;$results[$engine][$variant]=['ms'=>$ms,'sha256'=>$hash,'plan'=>$plan];
 }
 $db->close();unset($db);
}
echo json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
