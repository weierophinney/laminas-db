<?php

/**
 * @see       https://github.com/laminas/laminas-db for the canonical source repository
 * @copyright https://github.com/laminas/laminas-db/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-db/blob/master/LICENSE.md New BSD License
 */

namespace LaminasTest\Db\TestAsset;

use Laminas\Db\Adapter\Platform\Mysql;

class TrustingMysqlPlatform extends Mysql
{
    public function quoteValue(string $value): string
    {
        return $this->quoteTrustedValue($value);
    }
}
