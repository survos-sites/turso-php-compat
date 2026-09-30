<?php
declare(strict_types=1);
require '/harness/vendor/autoload.php';
$fixtureDir=sys_get_temp_dir().'/turso-fixtures-'.getmypid();
mkdir($fixtureDir,0700,true);
foreach(glob('/fixtures/*') as $fixture) { if(is_file($fixture) && !preg_match('/-(wal|shm)$/',$fixture)) copy($fixture,$fixtureDir.'/'.basename($fixture)); }
define('FIXTURE_DIR',$fixtureDir);
function fixture(string $name): string { return FIXTURE_DIR.'/'.$name; }
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;

#[ORM\Entity]
#[ORM\Table(name: 'smoke_record')]
class SmokeRecord {
    #[ORM\Id, ORM\Column(type: 'integer'), ORM\GeneratedValue]
    public ?int $id = null;
    #[ORM\Column(length: 120)] public string $title = 'hello';
    #[ORM\Column(type: 'json')] public array $payload = ['year'=>1932];
}
class SmokeKernel extends Kernel {
    public function registerBundles(): iterable { yield new FrameworkBundle(); }
    public function registerContainerConfiguration(LoaderInterface $loader): void {
        $loader->load(function(ContainerBuilder $c) { $c->loadFromExtension('framework', ['secret'=>'isolated-test-only','test'=>true,'http_method_override'=>false]); });
    }
    public function getCacheDir(): string { return sys_get_temp_dir().'/symfony-smoke-'.getmypid(); }
    public function getLogDir(): string { return sys_get_temp_dir().'/symfony-smoke-logs'; }
    public function getProjectDir(): string { return '/harness'; }
}
function pdo(string $name='minimal.sqlite'): PDO {
    return new PDO('sqlite:file:'.fixture($name).'?mode=ro', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function dbal(?string $path=null): Doctrine\DBAL\Connection {
    return DriverManager::getConnection($path ? ['driver'=>'pdo_sqlite','path'=>$path] : ['driver'=>'pdo_sqlite','memory'=>true]);
}
function folioSql(): array {
    return [
        'counts'=>"SELECT 'item' AS name, count(*) AS n FROM item UNION ALL SELECT 'core',count(*) FROM core UNION ALL SELECT 'link',count(*) FROM link UNION ALL SELECT 'term',count(*) FROM term",
        'join'=>"SELECT c.code, count(i.id) AS n FROM core c LEFT JOIN item i ON i.core_id=c.id GROUP BY c.code ORDER BY c.code",
        'json'=>"SELECT id, json_valid(dto_data) AS valid, json_extract(dto_data,'$.year') AS year FROM item ORDER BY id LIMIT 5 OFFSET 2",
        'sort'=>"SELECT id,sort_key FROM item ORDER BY sort_key,id LIMIT 5 OFFSET 2",
        'translations'=>"SELECT s.code,t.target_locale,t.status FROM str s JOIN str_tr t ON t.str_code=s.code ORDER BY s.code,t.target_locale LIMIT 5",
        'catalog'=>"SELECT type,name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name",
    ];
}
function runCase(string $case): mixed {
    switch($case) {
        case 'pdo.open': return pdo()->query('SELECT id,label FROM sample ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        case 'sqlite3.open':
            $c=new SQLite3(fixture('minimal.sqlite'),SQLITE3_OPEN_READONLY);$r=$c->query('SELECT id,label FROM sample ORDER BY id');$rows=[];while($v=$r->fetchArray(SQLITE3_ASSOC))$rows[]=$v;return $rows;
        case 'pdo.parameters':
            $c=pdo();$s=$c->prepare('SELECT id,label,json_extract(payload,\'$.year\') AS year FROM sample WHERE id>:id ORDER BY id LIMIT :limit OFFSET :offset');
            $s->bindValue(':id',0,PDO::PARAM_INT);$s->bindValue(':limit',2,PDO::PARAM_INT);$s->bindValue(':offset',1,PDO::PARAM_INT);$s->execute();return $s->fetchAll(PDO::FETCH_ASSOC);
        case 'pdo.positional': $s=pdo()->prepare('SELECT label FROM sample WHERE id=?');$s->execute([2]);return $s->fetchColumn();
        case 'pdo.transactions':
            $c=new PDO('sqlite::memory:');$c->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$c->exec('CREATE TABLE t(id INTEGER PRIMARY KEY AUTOINCREMENT,label TEXT)');
            $c->beginTransaction();$c->exec("INSERT INTO t(label) VALUES('committed')");$id=$c->lastInsertId();$c->commit();
            $c->beginTransaction();$c->exec("INSERT INTO t(label) VALUES('rolled back')");$c->rollBack();return ['id'=>$id,'rows'=>$c->query('SELECT * FROM t')->fetchAll(PDO::FETCH_ASSOC),'transaction'=>$c->inTransaction()];
        case 'pdo.blob-null':
            $c=new PDO('sqlite::memory:');$c->exec('CREATE TABLE b(id INTEGER, data BLOB, optional TEXT)');$s=$c->prepare('INSERT INTO b VALUES(?,?,?)');$s->bindValue(1,1,PDO::PARAM_INT);$s->bindValue(2,"a\0b",PDO::PARAM_LOB);$s->bindValue(3,null,PDO::PARAM_NULL);$s->execute();return $c->query('SELECT id,hex(data) AS data,optional FROM b')->fetchAll(PDO::FETCH_ASSOC);
        case 'pdo.udf': $c=pdo();$c->sqliteCreateFunction('twice',fn($x)=>2*$x,1);return $c->query('SELECT twice(id) AS n FROM sample ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        case 'pdo.collation': $c=pdo();$c->sqliteCreateCollation('reverse',fn($a,$b)=>strcmp($b,$a));return $c->query('SELECT label FROM sample ORDER BY label COLLATE reverse')->fetchAll(PDO::FETCH_COLUMN);
        case 'pdo.constraint':
            $c=new PDO('sqlite::memory:');$c->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$c->exec('CREATE TABLE u(id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL)');$c->exec("INSERT INTO u VALUES(1,'a')");
            try{$result=$c->exec("INSERT INTO u VALUES(2,'a')");return ['threw'=>false,'return'=>$result,'error_info'=>$c->errorInfo(),'rows'=>$c->query('SELECT count(*) FROM u')->fetchColumn()];}catch(PDOException $e){return ['threw'=>true,'sqlstate'=>$e->errorInfo[0],'driver_code'=>$e->errorInfo[1],'rows'=>$c->query('SELECT count(*) FROM u')->fetchColumn()];}
        case 'pdo.readonly':
            $c=pdo();try{$result=$c->exec("INSERT INTO sample VALUES(99,'must not write','{}')");return ['rejected'=>false,'return'=>$result,'rows'=>$c->query('SELECT count(*) FROM sample')->fetchColumn()];}catch(PDOException $e){return ['rejected'=>true,'sqlstate'=>$e->errorInfo[0],'driver_code'=>$e->errorInfo[1],'rows'=>$c->query('SELECT count(*) FROM sample')->fetchColumn()];}
        case 'dbal.query':return dbal('file:'.fixture('minimal.sqlite').'?mode=ro')->fetchAllAssociative('SELECT label FROM sample WHERE id > ? ORDER BY id',[1]);
        case 'dbal.schema':
            $sm=dbal('file:'.fixture('minimal.sqlite').'?mode=ro')->createSchemaManager();$t=$sm->introspectTable('sample');return ['columns'=>array_values(array_map(fn($column)=>$column->getName(),$t->getColumns())),'pk'=>$t->getPrimaryKey()?->getColumns(),'indexes'=>array_keys($t->getIndexes())];
        case 'dbal.savepoint':
            $c=dbal();$c->executeStatement('CREATE TABLE t(id INTEGER)');$c->beginTransaction();$c->insert('t',['id'=>1]);$c->beginTransaction();$c->insert('t',['id'=>2]);$c->rollBack();$c->commit();return $c->fetchFirstColumn('SELECT id FROM t');
        case 'orm.crud':
            $em=new EntityManager(dbal(),ORMSetup::createAttributeMetadataConfiguration([__DIR__],true));$meta=$em->getClassMetadata(SmokeRecord::class);(new SchemaTool($em))->createSchema([$meta]);
            $e=new SmokeRecord();$em->persist($e);$em->flush();$id=$e->id;$em->clear();$r=$em->find(SmokeRecord::class,$id);$first=['id'=>$r->id,'title'=>$r->title,'payload'=>$r->payload];$r->title='updated';$em->flush();$em->clear();$rows=$em->createQuery('SELECT r FROM SmokeRecord r ORDER BY r.id')->getArrayResult();$r=$em->find(SmokeRecord::class,$id);$em->remove($r);$em->flush();return ['insert'=>$first,'update'=>$rows,'remaining'=>$em->getRepository(SmokeRecord::class)->count([])];
        case 'symfony.kernel':
            $k=new SmokeKernel('test',true);$k->boot();$k->getContainer()->get('event_dispatcher')->addListener('kernel.request',function($e){$e->getRequest()->attributes->set('_controller',fn()=>new JsonResponse(['rows'=>dbal('file:'.fixture('minimal.sqlite').'?mode=ro')->fetchOne('SELECT count(*) FROM sample')]));});$r=$k->handle(Request::create('/smoke'));$k->shutdown();return ['status'=>$r->getStatusCode(),'body'=>json_decode($r->getContent(),true)];
        case 'folio.schema':
            $sm=dbal('file:'.fixture('selected.folio').'?mode=ro')->createSchemaManager();$t=$sm->introspectTable('item');return ['columns'=>array_values(array_map(fn($column)=>$column->getName(),$t->getColumns())),'pk'=>$t->getPrimaryKey()?->getColumns(),'foreign_keys'=>count($t->getForeignKeys())];
        case 'folio.sqlite3':
            $c=new SQLite3(fixture('selected.folio'),SQLITE3_OPEN_READONLY);return ['items'=>$c->querySingle('SELECT count(*) FROM item'),'cores'=>$c->querySingle('SELECT count(*) FROM core')];
        case 'pixie.read':return pdo('moma.pixy.db')->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        default:
            if(str_starts_with($case,'folio.'))return pdo('selected.folio')->query(folioSql()[substr($case,6)])->fetchAll(PDO::FETCH_ASSOC);
            throw new RuntimeException('Unknown case '.$case);
    }
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    $case=$argv[1];
    try{$value=runCase($case);echo json_encode(['case'=>$case,'status'=>'ok','value'=>$value],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)."\n";}
    catch(Throwable $e){echo json_encode(['case'=>$case,'status'=>'error','class'=>get_class($e),'message'=>$e->getMessage()],JSON_INVALID_UTF8_SUBSTITUTE)."\n";exit(1);}
}
