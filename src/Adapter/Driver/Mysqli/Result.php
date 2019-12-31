<?php

/**
 * @see       https://github.com/laminas/laminas-db for the canonical source repository
 * @copyright https://github.com/laminas/laminas-db/blob/master/COPYRIGHT.md
 * @license   https://github.com/laminas/laminas-db/blob/master/LICENSE.md New BSD License
 */

namespace Laminas\Db\Adapter\Driver\Mysqli;

use Laminas\Db\Adapter\Driver\ResultInterface;
use Laminas\Db\Adapter\Exception;

/**
 * @category   Laminas
 * @package    Laminas_Db
 * @subpackage Adapter
 */
class Result implements \Iterator, ResultInterface
{

    /**
     * @var \mysqli|\mysqli_result|\mysqli_stmt
     */
    protected $resource = null;

    /**
     * @var bool
     */
    protected $isBuffered = null;

    /**
     * Cursor position
     * @var int
     */
    protected $position = 0;

    /**
     * Number of known rows
     * @var int
     */
    protected $numberOfRows = -1;

    /**
     * Is the current() operation already complete for this pointer position?
     * @var bool
     */
    protected $currentComplete = false;

    /**
     * @var bool
     */
    protected $nextComplete = false;

    /**
     * @var bool
     */
    protected $currentData = false;

    /**
     *
     * @var array
     */
    protected $statementBindValues = array('keys' => null, 'values' => array());

    /**
     * @var mixed
     */
    protected $generatedValue = null;

    /**
     * Initialize
     * @param mixed $resource
     * @param mixed $generatedValue
     * @param bool|null $isBuffered
     * @return Result
     */
    public function initialize($resource, $generatedValue, $isBuffered = null)
    {
        if (!$resource instanceof \mysqli && !$resource instanceof \mysqli_result && !$resource instanceof \mysqli_stmt) {
            throw new Exception\InvalidArgumentException('Invalid resource provided.');
        }

        if ($isBuffered !== null) {
            $this->isBuffered = $isBuffered;
        } else {
            if ($resource instanceof \mysqli || $resource instanceof \mysqli_result
                || $resource instanceof \mysqli_stmt && $resource->num_rows != 0) {
                $this->isBuffered = true;
            }
        }

        $this->resource = $resource;
        $this->generatedValue = $generatedValue;
        return $this;
    }

    /**
     * Force buffering
     * @throws Exception\RuntimeException
     */
    public function buffer()
    {
        if ($this->resource instanceof \mysqli_stmt && $this->isBuffered !== true) {
            if ($this->position > 0) {
                throw new Exception\RuntimeException('Cannot buffer a result set that has started iteration.');
            }
            $this->resource->store_result();
            $this->isBuffered = true;
        }
    }

    /**
     * @return bool|null
     */
    public function isBuffered()
    {
        return $this->isBuffered;
    }

    /**
     * Return the resource
     * @return mixed
     */
    public function getResource()
    {
        return $this->resource;
    }

    /**
     * Is query result?
     *
     * @return boolean
     */
    public function isQueryResult()
    {
        return ($this->resource->field_count > 0);
    }

    /**
     * Get affected rows
     * @return integer
     */
    public function getAffectedRows()
    {
        if ($this->resource instanceof \mysqli || $this->resource instanceof \mysqli_stmt) {
            return $this->resource->affected_rows;
        } else {
            return $this->resource->num_rows;
        }
    }

    /**
     * Current
     * @return mixed
     */
    public function current()
    {
        if ($this->currentComplete) {
            return $this->currentData;
        }

        if ($this->resource instanceof \mysqli_stmt) {
            $this->loadDataFromMysqliStatement();
            return $this->currentData;
        } else {
            $this->loadFromMysqliResult();
            return $this->currentData;
        }
    }

    /**
     * Mysqli's binding and returning of statement values
     *
     * Mysqli requires you to bind variables to the extension in order to
     * get data out.  These values have to be references:
     * @see http://php.net/manual/en/mysqli-stmt.bind-result.php
     *
     * @throws \RuntimeException
     * @return bool
     */
    protected function loadDataFromMysqliStatement()
    {
        $data = null;
        // build the default reference based bind structure, if it does not already exist
        if ($this->statementBindValues['keys'] === null) {
            $this->statementBindValues['keys'] = array();
            $resultResource = $this->resource->result_metadata();
            foreach ($resultResource->fetch_fields() as $col) {
                $this->statementBindValues['keys'][] = $col->name;
            }
            $this->statementBindValues['values'] = array_fill(0, count($this->statementBindValues['keys']), null);
            $refs = array();
            foreach ($this->statementBindValues['values'] as $i => &$f) {
                $refs[$i] = &$f;
            }
            call_user_func_array(array($this->resource, 'bind_result'), $this->statementBindValues['values']);
        }

        if (($r = $this->resource->fetch()) === null) {
            if (!$this->isBuffered) {
                $this->resource->close();
            }
            return false;
        } elseif ($r === false) {
            throw new Exception\RuntimeException($this->resource->error);
        }

        // dereference
        for ($i = 0; $i < count($this->statementBindValues['keys']); $i++) {
            $this->currentData[$this->statementBindValues['keys'][$i]] = $this->statementBindValues['values'][$i];
        }
        $this->currentComplete = true;
        $this->nextComplete = true;
        $this->position++;
        return true;
    }

    /**
     * Load from mysqli result
     *
     * @return boolean
     */
    protected function loadFromMysqliResult()
    {
        $this->currentData = null;

        if (($data = $this->resource->fetch_assoc()) === null) {
            return false;
        }

        $this->position++;
        $this->currentData = $data;
        $this->currentComplete = true;
        $this->nextComplete = true;
        $this->position++;
        return true;
    }

    /**
     * Next
     */
    public function next()
    {
        $this->currentComplete = false;

        if ($this->nextComplete == false) {
            $this->position++;
        }

        $this->nextComplete = false;
    }

    /**
     * Key
     * @return mixed
     */
    public function key()
    {
        return $this->position;
    }

    /**
     * Rewind
     */
    public function rewind()
    {
        if ($this->position !== 0) {
            if ($this->isBuffered === false) {
                throw new Exception\RuntimeException('Unbuffered results cannot be rewound for multiple iterations');
            }
        }
        $this->resource->data_seek(0); // works for both mysqli_result & mysqli_stmt
        $this->currentComplete = false;
        $this->position = 0;
    }

    /**
     * Valid
     * @return boolean
     */
    public function valid()
    {
        if ($this->currentComplete) {
            return true;
        }

        if ($this->resource instanceof \mysqli_stmt) {
            return $this->loadDataFromMysqliStatement();
        } else {
            return $this->loadFromMysqliResult();
        }
    }

    /**
     * Count
     * @return integer
     */
    public function count()
    {
        if ($this->isBuffered === false) {
            throw new Exception\RuntimeException('Row count is not available in unbuffered result sets.');
        }
        return $this->resource->num_rows;
    }

    /**
     * @return int
     */
    public function getFieldCount()
    {
        return $this->resource->field_count;
    }

    /**
     * @return mixed|null
     */
    public function getGeneratedValue()
    {
        return $this->generatedValue;
    }

}
