<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use Yiisoft\Db\Command\DDLCommand as AbstractDDLCommand;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Schema\QuoterInterface;

final class DDLCommand extends AbstractDDLCommand
{
    public function __construct(private QuoterInterface $quoter)
    {
        parent::__construct($quoter);
    }

    /**
     * Creates a SQL command for adding a default value constraint to an existing table.
     *
     * @param string $name the name of the default value constraint. The name will be properly quoted by the method.
     * @param string $table the table that the default value constraint will be added to. The name will be properly
     * quoted by the method.
     * @param string $column the name of the column to that the constraint will be added on. The name will be properly
     * quoted by the method.
     * @param mixed $value default value.
     *
     * @return string the SQL statement for adding a default value constraint to an existing table.
     *@throws Exception
     *
     */
    public function addDefaultValue(string $name, string $table, string $column, mixed $value): string
    {
        return 'ALTER TABLE '
            . $this->quoter->quoteTableName($table)
            . ' ADD CONSTRAINT '
            . $this->quoter->quoteColumnName($name)
            . ' DEFAULT ' . $this->quoter->quoteValue($value)
            . ' FOR ' . $this->quoter->quoteColumnName($column);
    }

    /**
     * Creates a SQL command for dropping a default value constraint.
     *
     * @param string $name the name of the default value constraint to be dropped. The name will be properly quoted by
     * the method.
     * @param string $table the table whose default value constraint is to be dropped. The name will be properly quoted
     * by the method.
     *
     * @return string the SQL statement for dropping a default value constraint.
     */
    public function dropDefaultValue(string $name, string $table): string
    {
        return 'ALTER TABLE '
            . $this->quoter->quoteTableName($table)
            . ' DROP CONSTRAINT '
            . $this->quoter->quoteColumnName($name);
    }
}
