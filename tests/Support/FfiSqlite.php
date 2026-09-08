<?php
declare(strict_types=1);
/* Test-only SQLite adapter for environments without pdo_sqlite. Never needed in deployment. */
final class FfiSqlite
{
    public \FFI $ffi; public $handle;
    public function __construct(string $path)
    {
        $this->ffi = \FFI::cdef(<<<'C'
typedef struct sqlite3 sqlite3;
typedef struct sqlite3_stmt sqlite3_stmt;
int sqlite3_open_v2(const char*, sqlite3**, int, const char*);
int sqlite3_close_v2(sqlite3*);
const char *sqlite3_errmsg(sqlite3*);
int sqlite3_exec(sqlite3*, const char*, void*, void*, char**);
int sqlite3_prepare_v2(sqlite3*, const char*, int, sqlite3_stmt**, const char**);
int sqlite3_finalize(sqlite3_stmt*);
int sqlite3_reset(sqlite3_stmt*);
int sqlite3_clear_bindings(sqlite3_stmt*);
int sqlite3_bind_int64(sqlite3_stmt*, int, long long);
int sqlite3_bind_null(sqlite3_stmt*, int);
int sqlite3_bind_text(sqlite3_stmt*, int, const char*, int, void*);
int sqlite3_step(sqlite3_stmt*);
int sqlite3_column_count(sqlite3_stmt*);
const char *sqlite3_column_name(sqlite3_stmt*, int);
int sqlite3_column_type(sqlite3_stmt*, int);
long long sqlite3_column_int64(sqlite3_stmt*, int);
double sqlite3_column_double(sqlite3_stmt*, int);
const unsigned char *sqlite3_column_text(sqlite3_stmt*, int);
long long sqlite3_last_insert_rowid(sqlite3*);
int sqlite3_changes(sqlite3*);
C
        , 'libsqlite3.so');
        $pointer = $this->ffi->new('sqlite3 *');
        if ($this->ffi->sqlite3_open_v2($path, \FFI::addr($pointer), 6 | 65536, null) !== 0) { throw new \RuntimeException('Cannot open test SQLite'); }
        $this->handle = $pointer;
    }
    public function prepare(string $sql): FfiStatement { return new FfiStatement($this, $sql); }
    public function exec(string $sql): int
    {
        $code = $this->ffi->sqlite3_exec($this->handle, $sql, null, null, null);
        if ($code !== 0) { throw new \RuntimeException('SQLite: ' . $this->ffi->sqlite3_errmsg($this->handle) . ' [' . $sql . ']'); }
        return $this->ffi->sqlite3_changes($this->handle);
    }
    public function lastInsertId(): string { return (string)$this->ffi->sqlite3_last_insert_rowid($this->handle); }
    public function __destruct() { $this->ffi->sqlite3_close_v2($this->handle); }
}
final class FfiStatement
{
    private FfiSqlite $db; private $statement; private array $bindings = []; private int $code = 101; private int $changes = 0;
    public function __construct(FfiSqlite $db, string $sql)
    {
        $this->db = $db; $pointer = $db->ffi->new('sqlite3_stmt *');
        if ($db->ffi->sqlite3_prepare_v2($db->handle, $sql, -1, \FFI::addr($pointer), null) !== 0) { throw new \RuntimeException('SQLite prepare: ' . $db->ffi->sqlite3_errmsg($db->handle) . ' [' . $sql . ']'); }
        $this->statement = $pointer;
    }
    public function bindValue(int $index, $value, int $type): void { $this->bindings[$index] = [$value, $type]; }
    public function execute(): void
    {
        $f = $this->db->ffi; $f->sqlite3_reset($this->statement); $f->sqlite3_clear_bindings($this->statement);
        foreach ($this->bindings as $index => [$value, $type]) {
            if ($value === null) { $code = $f->sqlite3_bind_null($this->statement, $index); }
            elseif ($type === \PDO::PARAM_INT) { $code = $f->sqlite3_bind_int64($this->statement, $index, (int)$value); }
            else { $string = (string)$value; $code = $f->sqlite3_bind_text($this->statement, $index, $string, strlen($string), $f->cast('void *', -1)); }
            if ($code !== 0) { throw new \RuntimeException('SQLite bind failed'); }
        }
        $this->code = $f->sqlite3_step($this->statement); $this->check(); $this->changes = $f->sqlite3_changes($this->db->handle);
    }
    private function check(): void
    {
        if (!in_array($this->code, [100, 101], true)) { throw new \RuntimeException('SQLite execution: ' . $this->db->ffi->sqlite3_errmsg($this->db->handle)); }
    }
    public function fetch(int $mode = \PDO::FETCH_ASSOC)
    {
        if ($this->code === 101) { return false; } $this->check(); $f = $this->db->ffi; $row = [];
        for ($i = 0; $i < $f->sqlite3_column_count($this->statement); $i++) {
            $name = $f->sqlite3_column_name($this->statement, $i); $type = $f->sqlite3_column_type($this->statement, $i);
            if ($type === 5) { $value = null; }
            elseif ($type === 1) { $value = (int)$f->sqlite3_column_int64($this->statement, $i); }
            elseif ($type === 2) { $value = $f->sqlite3_column_double($this->statement, $i); }
            else { $value = \FFI::string($f->cast('char *', $f->sqlite3_column_text($this->statement, $i))); }
            $row[$name] = $value;
        }
        $this->code = $f->sqlite3_step($this->statement); $this->check(); return $row;
    }
    public function fetchAll(int $mode = \PDO::FETCH_ASSOC): array { $rows = []; while (($row = $this->fetch($mode)) !== false) { $rows[] = $row; } return $rows; }
    public function fetchColumn(int $column = 0) { $row = $this->fetch(); return $row === false ? false : (array_values($row)[$column] ?? false); }
    public function rowCount(): int { return $this->changes; }
    public function __destruct() { $this->db->ffi->sqlite3_finalize($this->statement); }
}
