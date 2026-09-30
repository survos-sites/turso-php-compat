<?php
$c=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$c->exec('CREATE TABLE u(id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL)');
$c->exec("INSERT INTO u VALUES(1,'a')");
$r=[];
try {$r['exec_return']=$c->exec("INSERT INTO u VALUES(2,'a')");$r['error_info']=$c->errorInfo();}
catch(Throwable $e){$r['exception']=$e->getMessage();$r['error_info']=$e->errorInfo;}
$r['rows']=$c->query('SELECT * FROM u ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$r['indexes']=$c->query("SELECT name,sql FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($r,JSON_PRETTY_PRINT)."\n";
