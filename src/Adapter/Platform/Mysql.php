<?php

/**
 * @see       https://github.com/laminas/laminas-db for the canonical source repository
 * @copyright https://github.com/laminas/laminas-db/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-db/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Laminas\Db\Adapter\Platform;

use Laminas\Db\Adapter\Driver\DriverInterface;
use mysqli;
use PDO;

class Mysql extends AbstractPlatform
{
    /**
     * {@inheritDoc}
     */
    protected $quoteIdentifier = ['`', '`'];

    /**
     * {@inheritDoc}
     */
    protected $quoteIdentifierTo = '``';

    /**
     * @var DriverInterface
     */
    protected $resource = null;

    /**
     * NOTE: Include dashes for MySQL only, need tests for others platforms
     *
     * @var string
     */
    protected $quoteIdentifierFragmentPattern = '/([^0-9,a-z,A-Z$_\-:])/i';

    public function __construct(DriverInterface $driver = null)
    {
        if ($driver) {
            $this->setDriver($driver);
        }
    }

    public function setDriver(DriverInterface $driver): Mysql
    {
        $this->resource = $driver;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getName(): string
    {
        return 'MySQL';
    }

    /**
     * {@inheritDoc}
     */
    public function quoteIdentifierChain(array $identifierChain): string
    {
        return '`' . implode('`.`', (array) str_replace('`', '``', $identifierChain)) . '`';
    }

    /**
     * {@inheritDoc}
     */
    public function quoteValue(string $value): string
    {
        $resource = $this->resource->getConnection()->getResource();
        if ($resource instanceof mysqli) {
            return '\'' . $resource->real_escape_string($value) . '\'';
        }
        if ($resource instanceof PDO) {
            return $resource->quote($value);
        }
        return parent::quoteValue($value);
    }

    /**
     * {@inheritDoc}
     */
    public function quoteTrustedValue(string $value): string
    {
        $resource = $this->resource->getConnection()->getResource();
        if ($resource instanceof mysqli) {
            return '\'' . $resource->real_escape_string($value) . '\'';
        }
        if ($resource instanceof PDO) {
            return $resource->quote($value);
        }
        return parent::quoteTrustedValue($value);
    }
}
