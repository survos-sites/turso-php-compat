<?php

declare(strict_types=1);
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/NativeConnection.php';
require __DIR__ . '/Driver.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Survos\TursoPrototype\Driver;

$source = getenv('TURSO_SOURCE') ?: throw new RuntimeException('Set TURSO_SOURCE');
$ext = PHP_OS_FAMILY === 'Darwin' ? 'dylib' : 'so';
$observed = [];
foreach (['sqlite', 'turso'] as $engine) {
    $db = DriverManager::getConnection($engine === 'sqlite' ? ['driver' => 'pdo_sqlite', 'memory' => true] : [
        'driverClass' => Driver::class, 'path' => ':memory:',
        'library' => (getenv('TURSO_LIBRARY') ?: "$source/target/debug/libturso_sdk_kit.$ext"), 'header' => "$source/sdk-kit/turso.h",
    ]);
    $db->executeStatement('CREATE TABLE movies (id INTEGER PRIMARY KEY, title TEXT NOT NULL, year INTEGER, metadata TEXT)');
    $db->executeStatement('CREATE TABLE ratings (movie_id INTEGER PRIMARY KEY, votes INTEGER NOT NULL, rating REAL)');
    $db->beginTransaction();
    foreach ([[1, "L’été d’Ada", 2001, '{"genre":"drama"}'], [2, 'Second', 1999, '{"genre":"comedy"}'], [3, 'Unrated', null, '{}']] as $row) {
        $db->executeStatement('INSERT INTO movies VALUES (?, ?, ?, ?)', $row, [ParameterType::INTEGER, ParameterType::STRING, $row[2] === null ? ParameterType::NULL : ParameterType::INTEGER, ParameterType::STRING]);
    }
    $db->executeStatement('INSERT INTO ratings VALUES (1, 120, 8.5)');
    $db->executeStatement('INSERT INTO ratings VALUES (2, 90, 7.0)');
    $db->commit();
    $db->executeStatement('CREATE INDEX movie_year ON movies(year, id)');
    $db->executeStatement('CREATE INDEX rating_votes ON ratings(votes, movie_id)');
    $cases = [
        'join_order_limit' => ['SELECT m.id, m.title, r.votes FROM movies m JOIN ratings r ON r.movie_id=m.id WHERE m.year >= ? ORDER BY r.votes DESC, m.id LIMIT 10', [1990], [ParameterType::INTEGER], [[1, "L’été d’Ada", 120], [2, 'Second', 90]]],
        'left_join_null' => ['SELECT m.id FROM movies m LEFT JOIN ratings r ON r.movie_id=m.id WHERE r.movie_id IS NULL', [], [], [[3]]],
        'aggregate' => ['SELECT COUNT(*), SUM(votes), AVG(rating) FROM ratings', [], [], [[2, 210, 7.75]]],
        'json' => ["SELECT id FROM movies WHERE json_extract(metadata, '$.genre') = ?", ['drama'], [], [[1]]],
        'pagination' => ['SELECT id FROM movies ORDER BY id LIMIT 1 OFFSET 1', [], [], [[2]]],
        'group_having' => ['SELECT year, COUNT(*) FROM movies GROUP BY year HAVING COUNT(*) >= 1 ORDER BY year', [], [], [[null, 1], [1999, 1], [2001, 1]]],
    ];
    foreach ($cases as $name => [$sql, $params, $types, $expected]) {
        $actual = $db->executeQuery($sql, $params, $types)->fetchAllNumeric();
        if ($actual !== $expected) throw new RuntimeException("$engine/$name mismatch: " . json_encode($actual));
        $observed[$engine][$name] = $actual;
    }
    if ($db->executeStatement('UPDATE ratings SET votes=votes+1 WHERE movie_id=1') !== 1) throw new RuntimeException('Update count mismatch');
    $db->beginTransaction();
    $db->executeStatement('DELETE FROM movies WHERE id=3');
    $db->rollBack();
    if ((int) $db->fetchOne('SELECT COUNT(*) FROM movies') !== 3) throw new RuntimeException('Delete rollback failed');
    if ($db->executeStatement('DELETE FROM movies WHERE id=3') !== 1) throw new RuntimeException('Delete count mismatch');
    if ((int) $db->fetchOne('SELECT votes FROM ratings WHERE movie_id=1') !== 121) throw new RuntimeException('Update failed');
    $db->executeStatement('DROP INDEX movie_year');
    $db->close();
    echo "$engine: PASS — joins, aggregation, grouping, JSON, Unicode, pagination, indexes, update/delete, rollback\n";
}
if ($observed['sqlite'] !== $observed['turso']) throw new RuntimeException('Engines disagree');
echo "Small synthetic relational correctness tests only; no performance conclusion.\n";
