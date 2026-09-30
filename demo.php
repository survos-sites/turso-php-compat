<?php
// Ordinary Doctrine DBAL. Run with ./demo.sh; the engine is selected by the container.
declare(strict_types=1);
require '/harness/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;

// All reads use a disposable copy; the original Folio is mounted read-only.
$path = sys_get_temp_dir().'/demo-'.getmypid().'.folio';
copy('/fixtures/selected.folio', $path);

$db = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'path' => $path,
]);

$rows = $db->fetchAllAssociative(<<<'SQL'
    SELECT c.code, c.label, count(i.id) AS items
    FROM core c
    LEFT JOIN item i ON i.core_id = c.id
    GROUP BY c.code, c.label
    ORDER BY c.code
    SQL);

$sample = $db->fetchAllAssociative(<<<'SQL'
    SELECT id, label, sort_key
    FROM item
    WHERE core_id = ?
    ORDER BY sort_key, id
    LIMIT 3
    SQL, [$db->fetchOne('SELECT id FROM core WHERE code = ?', ['doc'])]);

echo json_encode([
    'engine' => getenv('ENGINE'),
    'php' => PHP_VERSION,
    'dbal' => Composer\InstalledVersions::getPrettyVersion('doctrine/dbal'),
    'cores' => $rows,
    'sample' => $sample,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
$db->close();
