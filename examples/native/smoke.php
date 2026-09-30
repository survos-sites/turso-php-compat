<?php

declare(strict_types=1);
require getenv('DBAL_AUTOLOAD') ?: __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/NativeConnection.php';
require __DIR__ . '/Driver.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Survos\TursoPrototype\Driver;

$source = getenv('TURSO_SOURCE') ?: throw new RuntimeException('Set TURSO_SOURCE to the pinned checkout');
$extension = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$directory = sys_get_temp_dir() . '/turso-native-' . bin2hex(random_bytes(6));
mkdir($directory);
$outputs = [];
foreach (['sqlite', 'turso'] as $engine) {
    $params = $engine === 'sqlite' ? ['driver' => 'pdo_sqlite', 'path' => "$directory/test.sqlite"] : [
        'driverClass' => Driver::class, 'path' => "$directory/test.turso",
        'library' => (getenv('TURSO_LIBRARY') ?: "$source/target/debug/libturso_sdk_kit.$extension"), 'header' => "$source/sdk-kit/turso.h",
    ];
    $db = DriverManager::getConnection($params);
    $db->executeStatement('CREATE TABLE teachers (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE)');
    $db->executeStatement('INSERT INTO teachers (name) VALUES (?)', ['Ada']);
    if ((int) $db->lastInsertId() !== 1) throw new RuntimeException('Incorrect inserted ID');
    $db->beginTransaction();
    $db->executeStatement('INSERT INTO teachers (name) VALUES (?)', ['Rollback']);
    $db->rollBack();
    $db->beginTransaction();
    $db->executeStatement('INSERT INTO teachers (name) VALUES (:name)', ['name' => 'Grace']);
    $db->commit();
    $uniqueRejected = false;
    try { $db->executeStatement('INSERT INTO teachers (name) VALUES (?)', ['Ada']); }
    catch (Doctrine\DBAL\Exception $e) { $uniqueRejected = true; }
    if (!$uniqueRejected) throw new RuntimeException('Duplicate insert did not throw');
    $rows = $db->fetchAllAssociative('SELECT id, name FROM teachers WHERE id >= ? ORDER BY id', [1], [ParameterType::INTEGER]);
    if ($rows !== [['id' => 1, 'name' => 'Ada'], ['id' => 2, 'name' => 'Grace']]) throw new RuntimeException('Incorrect rows');
    $types = $db->fetchNumeric('SELECT ? AS empty_text, ? AS nullable, ? AS number, ? AS flag', ['', null, 42, true], [ParameterType::STRING, ParameterType::NULL, ParameterType::INTEGER, ParameterType::BOOLEAN]);
    if ($types !== ['', null, 42, 1]) throw new RuntimeException('Incorrect types');
    $db->close();
    unset($db);
    $db = DriverManager::getConnection($params);
    if ((int) $db->fetchOne('SELECT COUNT(*) FROM teachers') !== 2) throw new RuntimeException('Persistence failed');
    $outputs[$engine] = compact('rows', 'types', 'uniqueRejected');
    $db->close();
    unset($db);
    echo "$engine: PASS — create, bind, read, commit, rollback, UNIQUE error, types, reopen\n";
}
if ($outputs['sqlite'] !== $outputs['turso']) throw new RuntimeException('Engine results differ');
echo 'PHP ' . PHP_VERSION . '; DBAL ' . Composer\InstalledVersions::getPrettyVersion('doctrine/dbal') . "\n";
echo "Separate database files retained in $directory\n";
