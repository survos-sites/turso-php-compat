<?php
// Application-path timings; report the actual SDK profile and adapter limitations.
declare(strict_types=1);
require __DIR__ . '/../native/vendor/autoload.php';
require __DIR__ . '/../native/NativeConnection.php';
require __DIR__ . '/../native/Driver.php';
use Doctrine\DBAL\DriverManager;
use Survos\TursoPrototype\Driver;
$dir = $argv[1] ?? throw new RuntimeException('Usage: compare.php /path/to/prepared-imdb');
$source = getenv('TURSO_SOURCE') ?: throw new RuntimeException('Set TURSO_SOURCE');
$ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
if (!is_file("$dir/base.sqlite")) throw new RuntimeException('Prepare base.sqlite first');
if (!is_file("$dir/dataset.json")) throw new RuntimeException('Dataset import has not completed');
$run = "$dir/run-" . bin2hex(random_bytes(6)); mkdir($run);
$library = getenv('TURSO_LIBRARY') ?: "$source/target/debug/libturso_sdk_kit.$ext";
$profile = getenv('TURSO_BUILD_PROFILE') ?: 'debug';
$report = ['build_profile'=>$profile, 'library_sha256'=>hash_file('sha256',$library), 'notice' => 'Buffered PHP adapter; preliminary application-path timings; not controlled cold-cache measurements', 'php'=>PHP_VERSION, 'source_sha256'=>hash_file('sha256', "$dir/base.sqlite")];
$queries = [
    'year_filter' => "SELECT id,title,year FROM titles WHERE year BETWEEN 1990 AND 1999 ORDER BY year,id LIMIT 100",
    'title_lookup' => "SELECT id,title FROM titles WHERE title='Hamlet' ORDER BY id LIMIT 100",
    'join_top_votes' => "SELECT t.id,t.title,r.votes FROM ratings r JOIN titles t ON t.id=r.title_id WHERE r.votes>=10000 ORDER BY r.votes DESC,t.id LIMIT 100",
    'group_counts' => "SELECT kind,COUNT(*) AS n FROM titles GROUP BY kind ORDER BY kind",
    'missing_rating' => "SELECT t.id FROM titles t LEFT JOIN ratings r ON r.title_id=t.id WHERE r.title_id IS NULL ORDER BY t.id LIMIT 100",
];
$reference = [];
$order = getenv('ENGINE_ORDER') === 'turso-first' ? ['turso','sqlite'] : ['sqlite','turso'];
$report['engine_order']=$order;
$report['dataset']=json_decode(file_get_contents("$dir/dataset.json"),true,512,JSON_THROW_ON_ERROR);
$report['sources']=json_decode(file_get_contents("$dir/sources.json"),true,512,JSON_THROW_ON_ERROR);
foreach ($order as $engine) {
    $path = "$run/test." . ($engine === 'sqlite' ? 'sqlite' : 'turso');
    $started = hrtime(true);
    if (!copy("$dir/base.sqlite", $path)) throw new RuntimeException('Copy failed');
    $report[$engine]['copy_seconds']=(hrtime(true)-$started)/1e9;
    $db = DriverManager::getConnection($engine === 'sqlite' ? ['driver'=>'pdo_sqlite','path'=>$path] : ['driverClass'=>Driver::class,'path'=>$path,'library'=>$library,'header'=>"$source/sdk-kit/turso.h"]);
    $report[$engine]['journal_mode']=$db->fetchOne('PRAGMA journal_mode=WAL');
    $db->executeStatement('PRAGMA synchronous=FULL');
    $report[$engine]['synchronous']=$db->fetchOne('PRAGMA synchronous');
    $report[$engine]['version']=$db->fetchOne('SELECT sqlite_version()');
    foreach (['titles','ratings'] as $table) {
        $count=(int)$db->fetchOne("SELECT COUNT(*) FROM $table");
        if ($count !== $report['dataset']['rows'][$table]) throw new RuntimeException("Row count mismatch: $engine/$table");
        $report[$engine]['row_counts'][$table]=$count;
    }
    $started=hrtime(true);
    foreach (['CREATE INDEX titles_year ON titles(year,id)', 'CREATE INDEX titles_title ON titles(title,id)', 'CREATE INDEX ratings_votes ON ratings(votes DESC,title_id)'] as $sql) {
        $indexStart=hrtime(true);
        $db->executeStatement($sql);
        $report[$engine]['indexes'][]=['sql'=>$sql,'seconds'=>(hrtime(true)-$indexStart)/1e9];
        echo "$engine: $sql complete\n";
    }
    $report[$engine]['index_seconds']=(hrtime(true)-$started)/1e9;
    file_put_contents("$run/results.json",json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    foreach ($queries as $name=>$sql) {
        $report[$engine]['plans'][$name]=$db->fetchAllNumeric('EXPLAIN QUERY PLAN '.$sql);
        $times=[];
        for ($i=0;$i<4;$i++) {
            $started=hrtime(true); $rows=$db->fetchAllNumeric($sql); $times[]=(hrtime(true)-$started)/1e6;
            $hash=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
            if (isset($reference[$name]) && $reference[$name]!==$hash) throw new RuntimeException("Result mismatch: $engine/$name");
            $reference[$name]=$hash;
        }
        echo "$engine: $name matches (" . count($rows) . " rows)\n";
        $report[$engine]['queries'][$name]=['first_ms'=>$times[0],'subsequent_ms'=>array_slice($times,1),'rows'=>count($rows),'sha256'=>$hash];
    }
    clearstatcache();
    foreach (glob($path.'*') as $file) $report[$engine]['files_before_checkpoint'][basename($file)]=filesize($file);
    $checkpointStart=hrtime(true);
    $report[$engine]['checkpoint_result']=$db->fetchAllNumeric('PRAGMA wal_checkpoint(TRUNCATE)');
    $report[$engine]['checkpoint_seconds']=(hrtime(true)-$checkpointStart)/1e9;
    $db->close();unset($db);clearstatcache();
    $sizes=[];foreach (glob($path.'*') as $file) $sizes[basename($file)]=filesize($file);
    $report[$engine]['files_after_close']=$sizes;
    file_put_contents("$run/results.json",json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo "$engine: index and query checks complete\n";
}
echo "Report: $run/results.json\n";
