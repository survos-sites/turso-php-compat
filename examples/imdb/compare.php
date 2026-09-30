<?php
// Preliminary application-path timings: the SDK is a debug build, not an engine benchmark.
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
$run = "$dir/run-" . bin2hex(random_bytes(6)); mkdir($run);
$report = ['notice' => 'DEBUG SDK; buffered PHP adapter; preliminary timings, not production performance', 'php'=>PHP_VERSION, 'source_sha256'=>hash_file('sha256', "$dir/base.sqlite")];
$queries = [
    'year_filter' => "SELECT id,title,year FROM titles WHERE year BETWEEN 1990 AND 1999 ORDER BY year,id LIMIT 100",
    'title_lookup' => "SELECT id,title FROM titles WHERE title='Hamlet' ORDER BY id LIMIT 100",
    'join_top_votes' => "SELECT t.id,t.title,r.votes FROM ratings r JOIN titles t ON t.id=r.title_id WHERE r.votes>=10000 ORDER BY r.votes DESC,t.id LIMIT 100",
    'group_counts' => "SELECT kind,COUNT(*) AS n FROM titles GROUP BY kind ORDER BY kind",
    'missing_rating' => "SELECT t.id FROM titles t LEFT JOIN ratings r ON r.title_id=t.id WHERE r.title_id IS NULL ORDER BY t.id LIMIT 100",
];
$reference = [];
foreach (['sqlite','turso'] as $engine) {
    $path = "$run/test." . ($engine === 'sqlite' ? 'sqlite' : 'turso');
    $started = hrtime(true);
    if (!copy("$dir/base.sqlite", $path)) throw new RuntimeException('Copy failed');
    $report[$engine]['copy_seconds']=(hrtime(true)-$started)/1e9;
    $db = DriverManager::getConnection($engine === 'sqlite' ? ['driver'=>'pdo_sqlite','path'=>$path] : ['driverClass'=>Driver::class,'path'=>$path,'library'=>"$source/target/debug/libturso_sdk_kit.$ext",'header'=>"$source/sdk-kit/turso.h"]);
    $started=hrtime(true);
    foreach (['CREATE INDEX titles_year ON titles(year,id)', 'CREATE INDEX titles_title ON titles(title,id)', 'CREATE INDEX ratings_votes ON ratings(votes DESC,title_id)'] as $sql) $db->executeStatement($sql);
    $report[$engine]['index_seconds']=(hrtime(true)-$started)/1e9;
    foreach ($queries as $name=>$sql) {
        $times=[];
        for ($i=0;$i<4;$i++) {
            $started=hrtime(true); $rows=$db->fetchAllNumeric($sql); $times[]=(hrtime(true)-$started)/1e6;
            $hash=hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
            if (isset($reference[$name]) && $reference[$name]!==$hash) throw new RuntimeException("Result mismatch: $engine/$name");
            $reference[$name]=$hash;
        }
        $report[$engine]['queries'][$name]=['first_ms'=>$times[0],'subsequent_ms'=>array_slice($times,1),'rows'=>count($rows),'sha256'=>$hash];
    }
    $db->close();unset($db);clearstatcache();
    $sizes=[];foreach (glob($path.'*') as $file) $sizes[basename($file)]=filesize($file);
    $report[$engine]['files_after_close']=$sizes;
    file_put_contents("$run/results.json",json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n");
    echo "$engine: index and query checks complete\n";
}
echo "Report: $run/results.json\n";
