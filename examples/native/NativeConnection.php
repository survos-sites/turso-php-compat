<?php

declare(strict_types=1);
namespace Survos\TursoPrototype;

/** Experimental synchronous binding. Results are buffered, not suitable for benchmarks yet. */
final class NativeConnection
{
    private \FFI $ffi;
    private \FFI\CData $db;
    private \FFI\CData $connection;

    public function __construct(string $path, string $library, string $header)
    {
        $declarations = preg_replace('/^#.*$/m', '', file_get_contents($header));
        // Preserve embedded NUL bytes: PHP auto-converts char* returns to strings.
        $declarations = str_replace('const char *turso_statement_row_value_bytes_ptr', 'const void *turso_statement_row_value_bytes_ptr', $declarations);
        $this->ffi = \FFI::cdef($declarations, $library);
        $f = $this->ffi;
        $config = $f->new('turso_database_config_t');
        $pathBuffer = $f->new('char[' . (strlen($path) + 1) . ']');
        \FFI::memcpy($pathBuffer, $path, strlen($path));
        $config->path = \FFI::addr($pathBuffer[0]);
        $this->db = $f->new('const turso_database_t *');
        $error = $f->new('const char *');
        $this->check($f->turso_database_new(\FFI::addr($config), \FFI::addr($this->db), \FFI::addr($error)), $error);
        try {
            $this->check($f->turso_database_open($this->db, \FFI::addr($error)), $error);
            $this->connection = $f->new('turso_connection_t *');
            $this->check($f->turso_database_connect($this->db, \FFI::addr($this->connection), \FFI::addr($error)), $error);
        } catch (\Throwable $e) {
            $f->turso_database_deinit($this->db);
            unset($this->db, $this->connection);
            throw $e;
        }
    }

    private function check(int $status, ?\FFI\CData $error = null): void
    {
        if ($status === 0) return;
        $message = $error !== null && !\FFI::isNull($error) ? \FFI::string($error) : "Turso status $status";
        if ($error !== null && !\FFI::isNull($error)) $this->ffi->turso_str_deinit($error);
        throw new \RuntimeException($message, $status);
    }

    /** @return array{columns: list<string>, rows: list<list<mixed>>, changes: int} */
    public function execute(string $sql, array $parameters = []): array
    {
        $f = $this->ffi;
        $stmt = $f->new('turso_statement_t *');
        $error = $f->new('const char *');
        $this->check($f->turso_connection_prepare_single($this->connection, $sql, \FFI::addr($stmt), \FFI::addr($error)), $error);
        try {
            foreach ($parameters as $key => $value) {
                $position = is_int($key) ? $key + 1 : $f->turso_statement_named_position($stmt, ltrim($key, ':@$'));
                if ($position < 1) throw new \InvalidArgumentException("Unknown parameter $key");
                $status = match (true) {
                    $value === null => $f->turso_statement_bind_positional_null($stmt, $position),
                    is_int($value), is_bool($value) => $f->turso_statement_bind_positional_int($stmt, $position, (int) $value),
                    is_float($value) => $f->turso_statement_bind_positional_double($stmt, $position, $value),
                    default => $f->turso_statement_bind_positional_text($stmt, $position, (string) $value, strlen((string) $value)),
                };
                $this->check($status);
            }
            $columns = [];
            for ($i = 0; $i < $f->turso_statement_column_count($stmt); ++$i) $columns[] = $f->turso_statement_column_name($stmt, $i);
            $rows = [];
            while (true) {
                $status = $f->turso_statement_step($stmt, \FFI::addr($error));
                if ($status === 1) break;
                if ($status === 3) {
                    $this->check($f->turso_statement_run_io($stmt, \FFI::addr($error)), $error);
                    continue;
                }
                if ($status !== 2) $this->check($status, $error);
                $row = [];
                foreach ($columns as $i => $_) {
                    $row[] = match ($f->turso_statement_row_value_kind($stmt, $i)) {
                        1 => $f->turso_statement_row_value_int($stmt, $i),
                        2 => $f->turso_statement_row_value_double($stmt, $i),
                        3, 4 => ($length = $f->turso_statement_row_value_bytes_count($stmt, $i)) === 0 ? '' : \FFI::string($f->turso_statement_row_value_bytes_ptr($stmt, $i), $length),
                        5 => null,
                        default => throw new \RuntimeException('Unsupported native value type'),
                    };
                }
                $rows[] = $row;
            }
            $changes = $f->turso_statement_n_change($stmt);
            do {
                $status = $f->turso_statement_finalize($stmt, \FFI::addr($error));
                if ($status === 3) $this->check($f->turso_statement_run_io($stmt, \FFI::addr($error)), $error);
                elseif ($status !== 1) $this->check($status, $error);
            } while ($status === 3);
            return compact('columns', 'rows', 'changes');
        } finally {
            $f->turso_statement_deinit($stmt);
        }
    }

    public function lastInsertId(): int { return $this->ffi->turso_connection_last_insert_rowid($this->connection); }
    public function __destruct()
    {
        if (isset($this->connection)) $this->ffi->turso_connection_deinit($this->connection);
        if (isset($this->db)) $this->ffi->turso_database_deinit($this->db);
    }
}
