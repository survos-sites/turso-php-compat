<?php
require dirname(__DIR__).'/tests/case.php';
header('Content-Type: application/json');
try { echo json_encode(['engine'=>getenv('ENGINE'),'php'=>PHP_VERSION,'zts'=>PHP_ZTS,'sapi'=>PHP_SAPI,'kernel'=>runCase('symfony.kernel'),'folio'=>runCase('folio.counts')],JSON_THROW_ON_ERROR); }
catch(Throwable $e){http_response_code(500);echo json_encode(['class'=>get_class($e),'error'=>$e->getMessage()]);}
