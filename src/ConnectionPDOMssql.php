<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use PDO;
use PDOException;
use Psr\Log\LogLevel;
use Yiisoft\Db\Cache\QueryCache;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Command\Command;
use Yiisoft\Db\Connection\Connection;
use Yiisoft\Db\Connection\ConnectionPDOInterface;
use Yiisoft\Db\Driver\PDODriver;
use Yiisoft\Db\Driver\PDOInterface;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidConfigException;

use function constant;

/**
 * The class Connection represents a connection to a database via [PDO](https://secure.php.net/manual/en/book.pdo.php).
 */
final class ConnectionPDOMssql extends Connection implements ConnectionPDOInterface
{
    private ?PDO $pdo = null;

    public function __construct(
        private PDODriver $driver,
        private QueryCache $queryCache,
        private SchemaCache $schemaCache
    ) {
        parent::__construct($queryCache);
    }

    /**
     * Reset the connection after cloning.
     */
    public function __clone()
    {
        $this->master = null;
        $this->slave = null;
        $this->transaction = null;

        if (strncmp($this->driver->getDsn(), 'sqlite::memory:', 15) !== 0) {
            /** reset PDO connection, unless its sqlite in-memory, which can only have one connection */
            $this->pdo = null;
        }
    }

    /**
     * Close the connection before serializing.
     *
     * @return array
     */
    public function __sleep(): array
    {
        $fields = (array) $this;

        unset(
            $fields["\000" . __CLASS__ . "\000" . 'pdo'],
            $fields["\000" . __CLASS__ . "\000" . 'master'],
            $fields["\000" . __CLASS__ . "\000" . 'slave'],
            $fields["\000" . __CLASS__ . "\000" . 'transaction'],
            $fields["\000" . __CLASS__ . "\000" . 'schema']
        );

        return array_keys($fields);
    }

    public function createCommand(?string $sql = null, array $params = []): Command
    {
        if ($sql !== null) {
            $sql = $this->quoteSql($sql);
        }

        $command = new Command($this, $this->queryCache, $sql);

        if ($this->logger !== null) {
            $command->setLogger($this->logger);
        }

        if ($this->profiler !== null) {
            $command->setProfiler($this->profiler);
        }

        return $command->bindValues($params);
    }

    public function close(): void
    {
        if (!empty($this->master)) {
            /** @var ConnectionPDOMssql */
            $db = $this->master;

            if ($this->pdo === $db->getPDO()) {
                $this->pdo = null;
            }

            $db->close();
            $this->master = null;
        }

        if ($this->pdo !== null) {
            $this->logger?->log(
                LogLevel::DEBUG,
                'Closing DB connection: ' . $this->driver->getDsn() . ' ' . __METHOD__,
            );

            $this->pdo = null;
            $this->transaction = null;
        }

        if (!empty($this->slave)) {
            $this->slave->close();
            $this->slave = null;
        }
    }

    public function getDriver(): PDODriver
    {
        return $this->driver;
    }

    public function getDriverName(): string
    {
        return 'sqlsrv';
    }

    public function getMasterPdo(): PDO|null
    {
        $this->open();
        return $this->pdo;
    }

    public function getPDO(): ?PDO
    {
        return $this->pdo;
    }

    public function getSchema(): Schema
    {
        return new Schema($this, $this->schemaCache);
    }

    public function getSlavePdo(bool $fallbackToMaster = true): ?PDO
    {
        /** @var ConnectionPDOMssql|null $db */
        $db = $this->getSlave(false);

        if ($db === null) {
            return $fallbackToMaster ? $this->getMasterPdo() : null;
        }

        return $db->getPDO();
    }

    public function isActive(): bool
    {
        return $this->pdo !== null;
    }

    public function open(): void
    {
        if (!empty($this->pdo)) {
            return;
        }

        if (!empty($this->masters)) {
            /** @var ConnectionPDOMssql|null */
            $db = $this->getMaster();

            if ($db !== null) {
                $this->pdo = $db->getPDO();
                return;
            }

            throw new InvalidConfigException('None of the master DB servers is available.');
        }

        if (empty($this->driver->getDsn())) {
            throw new InvalidConfigException('Connection::dsn cannot be empty.');
        }

        $token = 'Opening DB connection: ' . $this->driver->getDsn();

        try {
            $this->logger?->log(LogLevel::INFO, $token);
            $this->profiler?->begin($token, [__METHOD__]);
            $this->initConnection();
            $this->profiler?->end($token, [__METHOD__]);
        } catch (PDOException $e) {
            $this->profiler?->end($token, [__METHOD__]);
            $this->logger?->log(LogLevel::ERROR, $token);

            throw new Exception($e->getMessage(), (array) $e->errorInfo, $e);
        }
    }

    /**
     * Initializes the DB connection.
     *
     * This method is invoked right after the DB connection is established.
     *
     * The default implementation turns on `PDO::ATTR_EMULATE_PREPARES`.
     *
     * if {@see emulatePrepare} is true, and sets the database {@see charset} if it is not empty.
     *
     * It then triggers an {@see EVENT_AFTER_OPEN} event.
     */
    protected function initConnection(): void
    {
        $this->pdo = $this->driver->createConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if (in_array($this->getDriverName(), ['mssql', 'dblib'], true)) {
            $this->pdo->exec('SET ANSI_NULL_DFLT_ON ON');
        }
    }
}
