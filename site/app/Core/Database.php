<?php
declare(strict_types=1);
namespace Chengyu\Core;

/** Small, prepared-statement-only database boundary. No SQL is accepted from HTTP. */
final class Database
{
    private $connection;
    private string $driver;
    private int $depth = 0;
    private bool $rollbackOnly = false;
    private static $testFactory;

    public static function useTestFactory(callable $factory): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
            throw new \LogicException('Test adapters are CLI-only.');
        }
        self::$testFactory = $factory;
    }

    public static function drivers(): array
    {
        return self::$testFactory ? ['sqlite'] : (class_exists('PDO') ? \PDO::getAvailableDrivers() : []);
    }

    public function __construct(array $config)
    {
        $this->driver = $config['driver'] ?? 'mysql';
        if (self::$testFactory) {
            $this->connection = (self::$testFactory)($config);
        } elseif ($this->driver === 'mysql') {
            $host = $config['host'] ?? 'localhost';
            $name = $config['name'] ?? '';
            if (!preg_match('/^[a-zA-Z0-9._:\-]+$/D', $host) || !preg_match('/^[a-zA-Z0-9_\-]+$/D', $name)) {
                throw new \InvalidArgumentException('Invalid database host or name.');
            }
            $dsn = 'mysql:host=' . $host . ';port=' . (int)($config['port'] ?? 3306) . ';dbname=' . $name . ';charset=utf8mb4';
            $this->connection = new \PDO($dsn, $config['user'], $config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 8,
            ]);
            $this->connection->exec("SET time_zone = '+00:00'");
        } elseif ($this->driver === 'sqlite') {
            $this->connection = new \PDO('sqlite:' . $config['path'], null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        } else {
            throw new \InvalidArgumentException('Unsupported database driver.');
        }
        if ($this->driver === 'sqlite') {
            $this->connection->exec('PRAGMA busy_timeout=10000');
            $this->connection->exec('PRAGMA foreign_keys=ON');
            $this->connection->exec('PRAGMA journal_mode=WAL');
        }
    }

    public function driver(): string { return $this->driver; }
    public function inTransaction(): bool { return $this->depth>0; }
    public function native(): bool { return $this->connection instanceof \PDO; }
    public function lock(): string { return $this->driver === 'mysql' ? ' FOR UPDATE' : ''; }

    public function run(string $sql, array $params = [])
    {
        $statement = $this->connection->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR);
            $statement->bindValue($i + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }
    public function all(string $sql, array $params = []): array { return $this->run($sql, $params)->fetchAll(\PDO::FETCH_ASSOC); }
    public function one(string $sql, array $params = []): ?array { return $this->run($sql, $params)->fetch(\PDO::FETCH_ASSOC) ?: null; }
    public function value(string $sql, array $params = []) { return $this->run($sql, $params)->fetchColumn(); }
    public function execute(string $sql, array $params = []): int { return $this->run($sql, $params)->rowCount(); }
    public function raw(string $sql): void { $this->connection->exec($sql); }
    public function id(): int { return (int)$this->connection->lastInsertId(); }

    public function insert(string $table, array $data): int
    {
        self::identifier($table);
        foreach (array_keys($data) as $key) { self::identifier($key); }
        $columns = '`' . implode('`,`', array_keys($data)) . '`';
        $this->execute('INSERT INTO `' . $table . '` (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
        return $this->id();
    }

    public function update(string $table, int $id, array $data): int
    {
        self::identifier($table);
        $columns = [];
        foreach (array_keys($data) as $key) { self::identifier($key); $columns[] = '`' . $key . '`=?'; }
        return $this->execute('UPDATE `' . $table . '` SET ' . implode(',', $columns) . ' WHERE id=?', array_merge(array_values($data), [$id]));
    }

    private static function identifier(string $identifier): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $identifier)) { throw new \InvalidArgumentException('Invalid identifier'); }
    }

    /** A single read snapshot; no retry because callers may stream an external file. */
    public function snapshot(callable $callback)
    {
        if ($this->depth !== 0) { throw new \LogicException('A backup cannot join a write transaction.'); }
        if ($this->driver === 'mysql') {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        } else { $this->connection->exec('BEGIN'); }
        $this->depth = 1; $this->rollbackOnly = false;
        try { $result = $callback($this); $this->connection->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { try { $this->connection->exec('ROLLBACK'); } catch (\Throwable $ignored) {} throw $e; }
        finally { $this->depth = 0; $this->rollbackOnly = false; }
    }

    /** Nested calls share the outer transaction. External side effects must stay outside. */
    public function transaction(callable $callback)
    {
        if ($this->depth > 0) {
            try { return $callback($this); }
            catch (\Throwable $e) { $this->rollbackOnly = true; throw $e; }
        }
        for ($attempt = 0; ; $attempt++) {
            $begun = false;
            try {
                $this->connection->exec($this->driver === 'sqlite' ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
                $begun = true;
                $this->depth = 1; $this->rollbackOnly = false;
                $result = $callback($this);
                if ($this->rollbackOnly) { throw new \RuntimeException('A nested operation failed; the transaction was rolled back.'); }
                $this->connection->exec('COMMIT');
                $this->depth = 0;
                return $result;
            } catch (\Throwable $exception) {
                $this->depth = 0;
                if ($begun) { try { $this->connection->exec('ROLLBACK'); } catch (\Throwable $ignored) {} }
                $errno = $exception instanceof \PDOException ? ($exception->errorInfo[1] ?? 0) : 0;
                if ($attempt < 2 && in_array($errno, [1205, 1213], true)) { usleep(30000 * ($attempt + 1)); continue; }
                throw $exception;
            }
        }
    }
}
