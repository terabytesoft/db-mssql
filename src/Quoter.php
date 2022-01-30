<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use PDO;
use Yiisoft\Db\Schema\QuoterInterface;

final class Quoter implements QuoterInterface
{
    private array $columnQuoteCharacter = ['[', ']'];
    private array $tableQuoteCharacter = ['[', ']'];

    public function __construct(private string $tablePrefix = '', private ?PDO $pdo = null)
    {
    }

    public function quoteColumnName(string $name): string
    {
        if (preg_match('/^\[.*]$/', $name)) {
            return $name;
        }

        if (strpos($name, '(') !== false || strpos($name, '[[') !== false) {
            return $name;
        }

        if (($pos = strrpos($name, '.')) !== false) {
            $prefix = $this->quoteTableName(substr($name, 0, $pos)) . '.';
            $name = substr($name, $pos + 1);
        } else {
            $prefix = '';
        }

        if (strpos($name, '{{') !== false) {
            return $name;
        }

        return $prefix . $this->quoteSimpleColumnName($name);
    }

    public function quoteSql(string $sql): string
    {
        return preg_replace_callback(
            '/({{(%?[\w\-. ]+%?)}}|\\[\\[([\w\-. ]+)]])/',
            function ($matches) {
                if (isset($matches[3])) {
                    return $this->quoteColumnName($matches[3]);
                }

                return str_replace('%', $this->tablePrefix, $this->quoteTableName($matches[2]));
            },
            $sql
        );
    }

    public function quoteTableName(string $name): string
    {
        if (strpos($name, '(') === 0 && strpos($name, ')') === strlen($name) - 1) {
            return $name;
        }

        if (strpos($name, '{{') !== false) {
            return $name;
        }

        if (strpos($name, '.') === false) {
            return $this->quoteSimpleTableName($name);
        }

        $parts = $this->getTableNameParts($name);

        foreach ($parts as $i => $part) {
            $parts[$i] = $this->quoteSimpleTableName($part);
        }

        return implode('.', $parts);
    }

    public function quoteValue(int|string $value): int|string
    {
        if (!is_string($value)) {
            return $value;
        }

        if (($value = $this->pdo->quote($value)) !== false) {
            return $value;
        }

        /** the driver doesn't support quote (e.g. oci) */
        return "'" . addcslashes(str_replace("'", "''", $value), "\000\n\r\\\032") . "'";
    }

    /**
     * Splits full table name into parts
     *
     * @param string $name
     *
     * @return array
     */
    private function getTableNameParts(string $name): array
    {
        $parts = [$name];

        preg_match_all('/([^.\[\]]+)|\[([^\[\]]+)]/', $name, $matches);

        if (isset($matches[0]) && !empty($matches[0])) {
            $parts = $matches[0];
        }

        return str_replace(['[', ']'], '', $parts);
    }

    /**
     * Quotes a simple column name for use in a query.
     *
     * A simple column name should contain the column name only without any prefix. If the column name is already quoted
     * or is the asterisk character '*', this method will do nothing.
     *
     * @param string $name column name.
     *
     * @return string the properly quoted column name.
     */
    private function quoteSimpleColumnName(string $name): string
    {
        if (is_string($this->columnQuoteCharacter)) {
            $startingCharacter = $endingCharacter = $this->columnQuoteCharacter;
        } else {
            [$startingCharacter, $endingCharacter] = $this->columnQuoteCharacter;
        }

        return $name === '*' || strpos($name, $startingCharacter) !== false ? $name : $startingCharacter . $name
            . $endingCharacter;
    }

    /**
     * Quotes a simple table name for use in a query.
     *
     * A simple table name should contain the table name only without any schema prefix. If the table name is already
     * quoted, this method will do nothing.
     *
     * @param string $name table name.
     *
     * @return string the properly quoted table name.
     */
    private function quoteSimpleTableName(string $name): string
    {
        if (is_string($this->tableQuoteCharacter)) {
            $startingCharacter = $endingCharacter = $this->tableQuoteCharacter;
        } else {
            [$startingCharacter, $endingCharacter] = $this->tableQuoteCharacter;
        }

        return strpos($name, $startingCharacter) !== false ? $name : $startingCharacter . $name . $endingCharacter;
    }
}
