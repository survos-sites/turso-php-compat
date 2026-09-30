<?php
// Same ordinary DBAL application for both engines; no experimental features needed.
declare(strict_types=1);
require '/harness/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;

$path = sys_get_temp_dir().'/symfony-demo-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
copy('/fixtures/symfony-demo.sqlite', $path);
$db = DriverManager::getConnection(['driver'=>'pdo_sqlite', 'path'=>$path]);

$posts = $db->fetchAllAssociative(<<<'SQL'
    SELECT p.id, p.title, u.full_name AS author, count(c.id) AS comments
    FROM symfony_demo_post p
    JOIN symfony_demo_user u ON u.id=p.author_id
    LEFT JOIN symfony_demo_comment c ON c.post_id=p.id
    GROUP BY p.id, p.title, u.full_name
    ORDER BY p.id LIMIT 5
    SQL);
$tags = $db->fetchFirstColumn(<<<'SQL'
    SELECT t.name FROM symfony_demo_tag t
    JOIN symfony_demo_post_tag pt ON pt.tag_id=t.id
    WHERE pt.post_id = ? ORDER BY t.name
    SQL, [$posts[0]['id']]);

echo json_encode([
    'engine'=>getenv('ENGINE'), 'php'=>PHP_VERSION,
    'dbal'=>Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
    'dataset'=>'symfony/demo v2.8.1',
    'post_count'=>(int)$db->fetchOne('SELECT count(*) FROM symfony_demo_post'),
    'posts'=>$posts, 'first_post_tags'=>$tags,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
$db->close();
foreach ([$path, $path.'-wal', $path.'-shm'] as $file) { if(is_file($file)) unlink($file); }
