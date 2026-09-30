<?php

declare(strict_types=1);
namespace Survos\TursoPrototype;

use Doctrine\DBAL\Driver as D;
use Doctrine\DBAL\ParameterType;

final class NativeError extends \RuntimeException implements D\Exception
{
    public function getSQLState(): ?string { return null; }
}

final class Driver extends D\AbstractSQLiteDriver
{
    public function connect(array $params): D\Connection
    {
        return new Connection(new NativeConnection($params['path'], $params['library'], $params['header']));
    }
}

final class Connection implements D\Connection
{
    public function __construct(private NativeConnection $native) {}
    public function run(string $sql, array $params = []): BufferedResult
    {
        try { return new BufferedResult($this->native->execute($sql, $params)); }
        catch (\RuntimeException $e) { throw new NativeError($e->getMessage(), $e->getCode(), $e); }
    }
    public function prepare(string $sql): D\Statement { return new Statement($this, $sql); }
    public function query(string $sql): D\Result { return $this->run($sql); }
    public function exec(string $sql): int|string { return $this->run($sql)->rowCount(); }
    public function quote(string $value): string { return "'" . str_replace("'", "''", $value) . "'"; }
    public function lastInsertId(): int|string { return $this->native->lastInsertId(); }
    public function beginTransaction(): void { $this->exec('BEGIN'); }
    public function commit(): void { $this->exec('COMMIT'); }
    public function rollBack(): void { $this->exec('ROLLBACK'); }
    public function getNativeConnection(): NativeConnection { return $this->native; }
    public function getServerVersion(): string { return (string) $this->query('SELECT sqlite_version()')->fetchOne(); }
}

final class Statement implements D\Statement
{
    private array $values = [];
    public function __construct(private Connection $connection, private string $sql) {}
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->values[is_int($param) ? $param - 1 : $param] = match ($type) {
            ParameterType::NULL => null,
            ParameterType::INTEGER => (int) $value,
            ParameterType::BOOLEAN => (bool) $value,
            ParameterType::STRING, ParameterType::ASCII => (string) $value,
            default => throw new \LogicException('Prototype does not yet support binary/LOB binding'),
        };
    }
    public function execute(): D\Result { return $this->connection->run($this->sql, $this->values); }
}

final class BufferedResult implements D\Result
{
    private int $position = 0;
    public function __construct(private array $result) {}
    public function fetchNumeric(): array|false { return $this->result['rows'][$this->position++] ?? false; }
    public function fetchAssociative(): array|false
    {
        $row = $this->fetchNumeric();
        return $row === false ? false : array_combine($this->result['columns'], $row);
    }
    public function fetchOne(): mixed { $row = $this->fetchNumeric(); return $row === false ? false : $row[0]; }
    public function fetchAllNumeric(): array { $rows = []; while (($row = $this->fetchNumeric()) !== false) $rows[] = $row; return $rows; }
    public function fetchAllAssociative(): array { $rows = []; while (($row = $this->fetchAssociative()) !== false) $rows[] = $row; return $rows; }
    public function fetchFirstColumn(): array { return array_column($this->fetchAllNumeric(), 0); }
    public function rowCount(): int|string { return $this->result['changes']; }
    public function columnCount(): int { return count($this->result['columns']); }
    public function getColumnName(int $index): string { return $this->result['columns'][$index]; }
    public function free(): void { $this->result['rows'] = []; }
}
