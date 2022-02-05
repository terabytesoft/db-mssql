<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use InvalidArgumentException;
use Yiisoft\Db\Command\DMLCommand as AbstractDMLCommand;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Schema\QuoterInterface;
use Yiisoft\Db\Schema\SchemaInterface;

final class DMLCommand extends AbstractDMLCommand
{
    public function __construct(private QuoterInterface $quoter, private SchemaInterface $schema)
    {
        parent::__construct($quoter);
    }

    /**
     * Creates a SQL statement for resetting the sequence value of a table's primary key.
     *
     * The sequence will be reset such that the primary key of the next new row inserted will have the specified value
     * or 1.
     *
     * @param string $tableName the name of the table whose primary key sequence will be reset.
     * @param mixed $value the value for the primary key of the next new row inserted. If this is not set, the next new
     * row's primary key will have a value 1.
     *
     * @throws Exception|InvalidArgumentException if the table does not exist or there is no sequence associated with
     * the table.
     *
     * @return string the SQL statement for resetting sequence.
     */
    public function resetSequence(string $tableName, $value = null): string
    {
        $table = $this->schema->getTableSchema($tableName);

        if ($table !== null && $table->getSequenceName() !== null) {
            $tableName = $this->quoter->quoteTableName($tableName);

            if ($value === null) {
                $pk = $table->getPrimaryKey();
                $key = $this->quoter->quoteColumnName(reset($pk));
                $value = "(SELECT COALESCE(MAX($key),0) FROM $tableName)+1";
            } else {
                $value = (int)$value;
            }

            return "DBCC CHECKIDENT ('$tableName', RESEED, $value)";
        }

        throw new InvalidArgumentException("There is not sequence associated with table '$tableName'.");
    }
}
