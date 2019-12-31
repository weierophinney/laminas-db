<?php

/**
 * @see       https://github.com/laminas/laminas-db for the canonical source repository
 * @copyright https://github.com/laminas/laminas-db/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-db/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Laminas\Db\Adapter\Driver\Pdo;

use Laminas\Db\Adapter\Driver\AbstractConnection;
use Laminas\Db\Adapter\Driver\ConnectionInterface;
use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Exception;

class Connection extends AbstractConnection
{
    /**
     * @var Pdo
     */
    protected $driver = null;

    /**
     * @var \PDO
     */
    protected $resource = null;

    /**
     * @var string
     */
    protected $dsn = null;

    /**
     * Constructor
     *
     * @param  array|\PDO|null                    $connectionParameters
     * @throws Exception\InvalidArgumentException
     */
    public function __construct($connectionParameters = null)
    {
        if (is_array($connectionParameters)) {
            $this->setConnectionParameters($connectionParameters);
        } elseif ($connectionParameters instanceof \PDO) {
            $this->setResource($connectionParameters);
        } elseif (null !== $connectionParameters) {
            throw new Exception\InvalidArgumentException(
                '$connection must be an array of parameters, a PDO object or null'
            );
        }
    }

    /**
     * Set driver
     *
     * @param Pdo $driver
     * @return self Provides a fluent interface
     */
    public function setDriver(Pdo $driver)
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function setConnectionParameters(array $connectionParameters): ConnectionInterface
    {
        $this->connectionParameters = $connectionParameters;
        if (isset($connectionParameters['dsn'])) {
            $this->driverName = substr(
                $connectionParameters['dsn'],
                0,
                strpos($connectionParameters['dsn'], ':')
            );
        } elseif (isset($connectionParameters['pdodriver'])) {
            $this->driverName = strtolower($connectionParameters['pdodriver']);
        } elseif (isset($connectionParameters['driver'])) {
            $this->driverName = strtolower(substr(
                str_replace(['-', '_', ' '], '', $connectionParameters['driver']),
                3
            ));
        }
        return $this;
    }

    /**
     * Get the dsn string for this connection
     * @throws \Laminas\Db\Adapter\Exception\RunTimeException
     * @return string
     */
    public function getDsn()
    {
        if (! $this->dsn) {
            throw new Exception\RuntimeException(
                'The DSN has not been set or constructed from parameters in connect() for this Connection'
            );
        }

        return $this->dsn;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentSchema(): string
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        switch ($this->driverName) {
            case 'mysql':
                $sql = 'SELECT DATABASE()';
                break;
            case 'sqlite':
                return 'main';
            case 'sqlsrv':
            case 'dblib':
                $sql = 'SELECT SCHEMA_NAME()';
                break;
            case 'pgsql':
            default:
                $sql = 'SELECT CURRENT_SCHEMA';
                break;
        }

        /** @var $result \PDOStatement */
        $result = $this->resource->query($sql);
        if ($result instanceof \PDOStatement) {
            return $result->fetchColumn();
        }

        return false;
    }

    /**
     * Set resource
     *
     * @param  \PDO $resource
     * @return self Provides a fluent interface
     */
    public function setResource(\PDO $resource)
    {
        $this->resource = $resource;
        $this->driverName = strtolower($this->resource->getAttribute(\PDO::ATTR_DRIVER_NAME));

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidConnectionParametersException
     * @throws Exception\RuntimeException
     */
    public function connect(): ConnectionInterface
    {
        if ($this->resource) {
            return $this;
        }

        $dsn = $username = $password = $hostname = $database = null;
        $options = [];
        foreach ($this->connectionParameters as $key => $value) {
            switch (strtolower($key)) {
                case 'dsn':
                    $dsn = $value;
                    break;
                case 'driver':
                    $value = strtolower((string) $value);
                    if (strpos($value, 'pdo') === 0) {
                        $pdoDriver = str_replace(['-', '_', ' '], '', $value);
                        $pdoDriver = substr($pdoDriver, 3) ?: '';
                    }
                    break;
                case 'pdodriver':
                    $pdoDriver = (string) $value;
                    break;
                case 'user':
                case 'username':
                    $username = (string) $value;
                    break;
                case 'pass':
                case 'password':
                    $password = (string) $value;
                    break;
                case 'host':
                case 'hostname':
                    $hostname = (string) $value;
                    break;
                case 'port':
                    $port = (int) $value;
                    break;
                case 'database':
                case 'dbname':
                    $database = (string) $value;
                    break;
                case 'charset':
                    $charset    = (string) $value;
                    break;
                case 'unix_socket':
                    $unix_socket = (string) $value;
                    break;
                case 'version':
                    $version = (string) $value;
                    break;
                case 'driver_options':
                case 'options':
                    $value = (array) $value;
                    $options = array_diff_key($options, $value) + $value;
                    break;
                default:
                    $options[$key] = $value;
                    break;
            }
        }

        if (isset($hostname) && isset($unix_socket)) {
            throw new Exception\InvalidConnectionParametersException(
                'Ambiguous connection parameters, both hostname and unix_socket parameters were set',
                $this->connectionParameters
            );
        }

        if (! isset($dsn) && isset($pdoDriver)) {
            $dsn = [];
            switch ($pdoDriver) {
                case 'sqlite':
                    $dsn[] = $database;
                    break;
                case 'sqlsrv':
                    if (isset($database)) {
                        $dsn[] = "database={$database}";
                    }
                    if (isset($hostname)) {
                        $dsn[] = "server={$hostname}";
                    }
                    break;
                default:
                    if (isset($database)) {
                        $dsn[] = "dbname={$database}";
                    }
                    if (isset($hostname)) {
                        $dsn[] = "host={$hostname}";
                    }
                    if (isset($port)) {
                        $dsn[] = "port={$port}";
                    }
                    if (isset($charset) && $pdoDriver != 'pgsql') {
                        $dsn[] = "charset={$charset}";
                    }
                    if (isset($unix_socket)) {
                        $dsn[] = "unix_socket={$unix_socket}";
                    }
                    if (isset($version)) {
                        $dsn[] = "version={$version}";
                    }
                    break;
            }
            $dsn = $pdoDriver . ':' . implode(';', $dsn);
        } elseif (! isset($dsn)) {
            throw new Exception\InvalidConnectionParametersException(
                'A dsn was not provided or could not be constructed from your parameters',
                $this->connectionParameters
            );
        }

        $this->dsn = $dsn;

        try {
            $this->resource = new \PDO($dsn, $username, $password, $options);
            $this->resource->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            if (isset($charset) && $pdoDriver == 'pgsql') {
                $this->resource->exec('SET NAMES ' . $this->resource->quote($charset));
            }
            $this->driverName = strtolower($this->resource->getAttribute(\PDO::ATTR_DRIVER_NAME));
        } catch (\PDOException $e) {
            $code = $e->getCode();
            if (! is_long($code)) {
                $code = null;
            }
            throw new Exception\RuntimeException('Connect Error: ' . $e->getMessage(), $code, $e);
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function isConnected(): bool
    {
        return ($this->resource instanceof \PDO);
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction(): ConnectionInterface
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        if (0 === $this->nestedTransactionsCount) {
            $this->resource->beginTransaction();
            $this->inTransaction = true;
        }

        $this->nestedTransactionsCount ++;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function commit(): ConnectionInterface
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        if ($this->inTransaction) {
            $this->nestedTransactionsCount -= 1;
        }

        /*
         * This shouldn't check for being in a transaction since
         * after issuing a SET autocommit=0; we have to commit too.
         */
        if (0 === $this->nestedTransactionsCount) {
            $this->resource->commit();
            $this->inTransaction = false;
        }

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\RuntimeException
     */
    public function rollback(): ConnectionInterface
    {
        if (! $this->isConnected()) {
            throw new Exception\RuntimeException('Must be connected before you can rollback');
        }

        if (! $this->inTransaction()) {
            throw new Exception\RuntimeException('Must call beginTransaction() before you can rollback');
        }

        $this->resource->rollBack();

        $this->inTransaction           = false;
        $this->nestedTransactionsCount = 0;

        return $this;
    }

    /**
     * {@inheritDoc}
     *
     * @throws Exception\InvalidQueryException
     */
    public function execute(string $sql): ResultInterface
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        if ($this->profiler) {
            $this->profiler->profilerStart($sql);
        }

        $resultResource = $this->resource->query($sql);

        if ($this->profiler) {
            $this->profiler->profilerFinish($sql);
        }

        if ($resultResource === false) {
            $errorInfo = $this->resource->errorInfo();
            throw new Exception\InvalidQueryException($errorInfo[2]);
        }

        $result = $this->driver->createResultWithSql($resultResource, $sql);

        return $result;
    }

    /**
     * Prepare
     *
     * @param  string    $sql
     * @return Statement
     */
    public function prepare(string $sql): Statement
    {
        if (! $this->isConnected()) {
            $this->connect();
        }

        $statement = $this->driver->createStatement($sql);

        return $statement;
    }

    public function getLastGeneratedValue(string $name = null): string
    {
        if ($name === null
            && ($this->driverName == 'pgsql' || $this->driverName == 'firebird')) {
            return '';
        }

        try {
            return $this->resource->lastInsertId($name);
        } catch (\Exception $e) {
            // do nothing
        }

        return '';
    }
}
