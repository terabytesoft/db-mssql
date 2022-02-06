<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\PDO;

use JsonException;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Constraint\Constraint;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\InvalidArgumentException;
use Yiisoft\Db\Exception\InvalidConfigException;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Mssql\Condition\InConditionBuilder;
use Yiisoft\Db\Mssql\Condition\LikeConditionBuilder;
use Yiisoft\Db\Query\Conditions\InCondition;
use Yiisoft\Db\Query\Conditions\LikeCondition;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryBuilder;
use Yiisoft\Db\Schema\ColumnSchemaBuilder;

use Yiisoft\Db\Schema\Schema;
use function array_diff;
use function array_keys;
use function implode;
use function in_array;
use function ltrim;
use function preg_match;
use function preg_replace;
use function reset;
use function strrpos;
use function version_compare;

/**
 * QueryBuilder is the query builder for MS SQL Server databases (version 2008 and above).
 */
final class QueryBuilderPDOMssql extends QueryBuilder
{
    /**
     * @var array mapping from abstract column types (keys) to physical column types (values).
     */
    protected array $typeMap = [
        Schema::TYPE_PK => 'int IDENTITY PRIMARY KEY',
        Schema::TYPE_UPK => 'int IDENTITY PRIMARY KEY',
        Schema::TYPE_BIGPK => 'bigint IDENTITY PRIMARY KEY',
        Schema::TYPE_UBIGPK => 'bigint IDENTITY PRIMARY KEY',
        Schema::TYPE_CHAR => 'nchar(1)',
        Schema::TYPE_STRING => 'nvarchar(255)',
        Schema::TYPE_TEXT => 'nvarchar(max)',
        Schema::TYPE_TINYINT => 'tinyint',
        Schema::TYPE_SMALLINT => 'smallint',
        Schema::TYPE_INTEGER => 'int',
        Schema::TYPE_BIGINT => 'bigint',
        Schema::TYPE_FLOAT => 'float',
        Schema::TYPE_DOUBLE => 'float',
        Schema::TYPE_DECIMAL => 'decimal(18,0)',
        Schema::TYPE_DATETIME => 'datetime',
        Schema::TYPE_TIMESTAMP => 'datetime',
        Schema::TYPE_TIME => 'time',
        Schema::TYPE_DATE => 'date',
        Schema::TYPE_BINARY => 'varbinary(max)',
        Schema::TYPE_BOOLEAN => 'bit',
        Schema::TYPE_MONEY => 'decimal(19,4)',
    ];

    public function __construct(private ConnectionInterface $db)
    {
        parent::__construct($db->getQuoter(), $db->getSchema());
    }

    protected function defaultExpressionBuilders(): array
    {
        return array_merge(parent::defaultExpressionBuilders(), [
            InCondition::class => InConditionBuilder::class,
            LikeCondition::class => LikeConditionBuilder::class,
        ]);
    }

    /**
     * Builds the ORDER BY and LIMIT/OFFSET clauses and appends them to the given SQL.
     *
     * @param string $sql the existing SQL (without ORDER BY/LIMIT/OFFSET).
     * @param array $orderBy the order by columns. See {@see Query::orderBy} for more details
     * on how to specify this
     * parameter.
     * @param int|object|null $limit the limit number. See {@see Query::limit} for more details.
     * @param int|object|null $offset the offset number. See {@see Query::offset} for more
     * details.
     * @param array $params the binding parameters to be populated.
     *
     * @throws Exception|InvalidArgumentException
     *
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any).
     */
    public function buildOrderByAndLimit(string $sql, array $orderBy, $limit, $offset, array &$params = []): string
    {
        if (!$this->hasOffset($offset) && !$this->hasLimit($limit)) {
            $orderBy = $this->buildOrderBy($orderBy, $params);

            return $orderBy === '' ? $sql : $sql . $this->separator . $orderBy;
        }

        if (version_compare($this->db->getServerVersion(), '11', '<')) {
            return $this->oldBuildOrderByAndLimit($sql, $orderBy, $limit, $offset, $params);
        }

        return $this->newBuildOrderByAndLimit($sql, $orderBy, $limit, $offset, $params);
    }

    /**
     * Builds the ORDER BY/LIMIT/OFFSET clauses for SQL SERVER 2012 or newer.
     *
     * @param string $sql the existing SQL (without ORDER BY/LIMIT/OFFSET).
     * @param array $orderBy the order by columns. See {@see Query::orderBy} for more details on how to specify this
     * parameter.
     * @param Expression|Query|int|null $limit the limit number. See {@see Query::limit} for more details.
     * @param Expression|Query|int|null $offset the offset number. See {@see Query::offset} for more details.
     * @param array $params the binding parameters to be populated.
     *
     * @throws Exception|InvalidArgumentException
     *
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any).
     */
    protected function newBuildOrderByAndLimit(
        string $sql,
        array $orderBy,
        Expression|Query|int|null $limit,
        Expression|Query|int|null $offset,
        array &$params = []
    ): string {
        $orderBy = $this->buildOrderBy($orderBy, $params);

        if ($orderBy === '') {
            /** ORDER BY clause is required when FETCH and OFFSET are in the SQL */
            $orderBy = 'ORDER BY (SELECT NULL)';
        }

        $sql .= $this->separator . $orderBy;

        /**
         * {@see http://technet.microsoft.com/en-us/library/gg699618.aspx}
         */
        $offset = $this->hasOffset($offset) ? $offset : '0';
        $sql .= $this->separator . "OFFSET $offset ROWS";

        if ($this->hasLimit($limit)) {
            $sql .= $this->separator . "FETCH NEXT $limit ROWS ONLY";
        }

        return $sql;
    }

    /**
     * Builds the ORDER BY/LIMIT/OFFSET clauses for SQL SERVER 2005 to 2008.
     *
     * @param string $sql the existing SQL (without ORDER BY/LIMIT/OFFSET).
     * @param array $orderBy the order by columns. See {@see Query::orderBy} for more details on how to specify this
     * parameter.
     * @param int|Query|null $limit the limit number. See {@see Query::limit} for more details.
     * @param int|Query|null $offset the offset number. See {@see Query::offset} for more details.
     * @param array $params the binding parameters to be populated.
     *
     * @return string the SQL completed with ORDER BY/LIMIT/OFFSET (if any).
     *@throws Exception|InvalidArgumentException
     *
     */
    protected function oldBuildOrderByAndLimit(
        string $sql,
        array $orderBy,
        Query|int|null $limit,
        Query|int|null $offset,
        array &$params = []
    ): string {
        $orderBy = $this->buildOrderBy($orderBy, $params);

        if ($orderBy === '') {
            /** ROW_NUMBER() requires an ORDER BY clause */
            $orderBy = 'ORDER BY (SELECT NULL)';
        }

        $sql = preg_replace(
            '/^([\s(])*SELECT(\s+DISTINCT)?(?!\s*TOP\s*\()/i',
            "\\1SELECT\\2 rowNum = ROW_NUMBER() over ($orderBy),",
            $sql
        );

        if ($this->hasLimit($limit)) {
            $sql = "SELECT TOP $limit * FROM ($sql) sub";
        } else {
            $sql = "SELECT * FROM ($sql) sub";
        }

        if ($this->hasOffset($offset)) {
            $sql .= $this->separator . "WHERE rowNum > $offset";
        }

        return $sql;
    }

    /**
     * Builds a SQL statement for renaming a DB table.
     *
     * @param string $oldName the table to be renamed. The name will be properly quoted by the method.
     * @param string $newName the new table name. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB table.
     */
    public function renameTable(string $oldName, string $newName): string
    {
        return 'sp_rename ' .
            $this->db->getQuoter()->quoteTableName($oldName) . ', ' . $this->db->getQuoter()->quoteTableName($newName);
    }

    /**
     * Builds a SQL statement for renaming a column.
     *
     * @param string $table the table whose column is to be renamed. The name will be properly quoted by the method.
     * @param string $oldName the old name of the column. The name will be properly quoted by the method.
     * @param string $newName the new name of the column. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB column.
     */
    public function renameColumn(string $table, string $oldName, string $newName): string
    {
        $table = $this->db->getQuoter()->quoteTableName($table);
        $oldName = $this->db->getQuoter()->quoteColumnName($oldName);
        $newName = $this->db->getQuoter()->quoteColumnName($newName);

        return "sp_rename '$table.$oldName', $newName, 'COLUMN'";
    }

    /**
     * Builds a SQL statement for changing the definition of a column.
     *
     * @param string $table the table whose column is to be changed. The table name will be properly quoted by the
     * method.
     * @param string $column the name of the column to be changed. The name will be properly quoted by the method.
     * @param string $type the new column type. The [[getColumnType]] method will be invoked to convert abstract column
     * type (if any) into the physical one. Anything that is not recognized as abstract type will be kept in the
     * generated SQL.
     *
     * For example, 'string' will be turned into 'varchar(255)', while 'string not null' will become
     * 'varchar(255) not null'.
     *
     * @return string the SQL statement for changing the definition of a column.
     */
    public function alterColumn(string $table, string $column, string $type): string
    {
        $type = $this->getColumnType($type);

        return 'ALTER TABLE ' . $this->db->getQuoter()->quoteTableName($table) . ' ALTER COLUMN '
            . $this->db->getQuoter()->quoteColumnName($column) . ' '
            . $this->getColumnType($type);
    }

    /**
     * Builds a SQL statement for enabling or disabling integrity check.
     *
     * @param string $schema the schema of the tables.
     * @param string $table the table name.
     * @param bool $check whether to turn on or off the integrity check.
     *
     * @throws NotSupportedException
     *
     * @return string the SQL statement for checking integrity.
     */
    public function checkIntegrity(string $schema = '', string $table = '', bool $check = true): string
    {
        /** @var ConnectionPDOMssql $db */
        $db = $this->db;

        $enable = $check ? 'CHECK' : 'NOCHECK';
        $schema = $schema ?: $db->getSchema()->getDefaultSchema();
        $tableNames = $db->getTableSchema($table) ? [$table] : $db->getSchema()->getTableNames($schema);
        $viewNames = $db->getSchema()->getViewNames($schema);
        $tableNames = array_diff($tableNames, $viewNames);
        $command = '';

        foreach ($tableNames as $tableName) {
            $tableName = $db->getQuoter()->quoteTableName("$schema.$tableName");
            $command .= "ALTER TABLE $tableName $enable CONSTRAINT ALL; ";
        }

        return $command;
    }

    /**
     * Builds a SQL command for adding or updating a comment to a table or a column. The command built will check if a
     * comment already exists. If so, it will be updated, otherwise, it will be added.
     *
     * @param string $comment the text of the comment to be added. The comment will be properly quoted by the method.
     * @param string $table the table to be commented or whose column is to be commented. The table name will be
     * properly quoted by the method.
     * @param string|null $column optional. The name of the column to be commented. If empty, the command will add the
     * comment to the table instead. The column name will be properly quoted by the method.
     *
     * @throws \Exception|InvalidArgumentException if the table does not exist.
     *
     * @return string the SQL statement for adding a comment.
     */
    protected function buildAddCommentSql(string $comment, string $table, ?string $column = null): string
    {
        $tableSchema = $this->db->getSchema()->getTableSchema($table);

        if ($tableSchema === null) {
            throw new InvalidArgumentException("Table not found: $table");
        }

        $schemaName = $tableSchema->getSchemaName() ? "N'" . $tableSchema->getSchemaName() . "'" : 'SCHEMA_NAME()';
        $tableName = 'N' . $this->db->getQuoter()->quoteValue($tableSchema->getName());
        $columnName = $column ? 'N' . $this->db->getQuoter()->quoteValue($column) : null;
        $comment = 'N' . $this->db->getQuoter()->quoteValue($comment);

        $functionParams = "
            @name = N'MS_description',
            @value = $comment,
            @level0type = N'SCHEMA', @level0name = $schemaName,
            @level1type = N'TABLE', @level1name = $tableName"
            . ($column ? ", @level2type = N'COLUMN', @level2name = $columnName" : '') . ';';

        return "
            IF NOT EXISTS (
                    SELECT 1
                    FROM fn_listextendedproperty (
                        N'MS_description',
                        'SCHEMA', $schemaName,
                        'TABLE', $tableName,
                        " . ($column ? "'COLUMN', $columnName " : ' DEFAULT, DEFAULT ') . "
                    )
            )
                EXEC sys.sp_addextendedproperty $functionParams
            ELSE
                EXEC sys.sp_updateextendedproperty $functionParams
        ";
    }

    /**
     * Builds a SQL command for adding comment to column.
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the
     * method.
     * @param string $column the name of the column to be commented. The column name will be properly quoted by the
     * method.
     * @param string $comment the text of the comment to be added. The comment will be properly quoted by the method.
     *
     * @throws Exception
     *
     * @return string the SQL statement for adding comment on column.
     */
    public function addCommentOnColumn(string $table, string $column, string $comment): string
    {
        return $this->buildAddCommentSql($comment, $table, $column);
    }

    /**
     * Builds a SQL command for adding comment to table.
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the
     * method.
     * @param string $comment the text of the comment to be added. The comment will be properly quoted by the method.
     *
     * @throws Exception
     *
     * @return string the SQL statement for adding comment on table.
     */
    public function addCommentOnTable(string $table, string $comment): string
    {
        return $this->buildAddCommentSql($comment, $table);
    }

    /**
     * Builds a SQL command for removing a comment from a table or a column. The command built will check if a comment
     * already exists before trying to perform the removal.
     *
     * @param string $table the table that will have the comment removed or whose column will have the comment removed.
     * The table name will be properly quoted by the method.
     * @param string|null $column optional. The name of the column whose comment will be removed. If empty, the command
     * will remove the comment from the table instead. The column name will be properly quoted by the method.
     *
     * @throws \Exception|InvalidArgumentException if the table does not exist.
     *
     * @return string the SQL statement for removing the comment.
     */
    protected function buildRemoveCommentSql(string $table, ?string $column = null): string
    {
        $tableSchema = $this->db->getSchema()->getTableSchema($table);

        if ($tableSchema === null) {
            throw new InvalidArgumentException("Table not found: $table");
        }

        $schemaName = $tableSchema->getSchemaName() ? "N'" . $tableSchema->getSchemaName() . "'" : 'SCHEMA_NAME()';
        $tableName = 'N' . $this->db->getQuoter()->quoteValue($tableSchema->getName());
        $columnName = $column ? 'N' . $this->db->getQuoter()->quoteValue($column) : null;

        return "
            IF EXISTS (
                    SELECT 1
                    FROM fn_listextendedproperty (
                        N'MS_description',
                        'SCHEMA', $schemaName,
                        'TABLE', $tableName,
                        " . ($column ? "'COLUMN', $columnName " : ' DEFAULT, DEFAULT ') . "
                    )
            )
                EXEC sys.sp_dropextendedproperty
                    @name = N'MS_description',
                    @level0type = N'SCHEMA', @level0name = $schemaName,
                    @level1type = N'TABLE', @level1name = $tableName"
                    . ($column ? ", @level2type = N'COLUMN', @level2name = $columnName" : '') . ';';
    }

    /**
     * Builds a SQL command for adding comment to column.
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the
     * method.
     * @param string $column the name of the column to be commented. The column name will be properly quoted by the
     * method.
     *
     * @throws Exception
     *
     * @return string the SQL statement for adding comment on column.
     */
    public function dropCommentFromColumn(string $table, string $column): string
    {
        return $this->buildRemoveCommentSql($table, $column);
    }

    /**
     * Builds a SQL command for adding comment to table.
     *
     * @param string $table the table whose column is to be commented. The table name will be properly quoted by the
     * method.
     *
     * @throws Exception
     *
     * @return string the SQL statement for adding comment on column.
     */
    public function dropCommentFromTable(string $table): string
    {
        return $this->buildRemoveCommentSql($table);
    }

    /**
     * Returns an array of column names given model name.
     *
     * @param string|null $modelClass name of the model class.
     *
     * @return array|null array of column names
     */
    protected function getAllColumnNames(string $modelClass = null): ?array
    {
        if (!$modelClass) {
            return null;
        }

        $schema = $modelClass::getTableSchema();

        return array_keys($schema->columns);
    }

    /**
     * Creates a SELECT EXISTS() SQL statement.
     *
     * @param string $rawSql the subquery in a raw form to select from.
     *
     * @return string the SELECT EXISTS() SQL statement.
     */
    public function selectExists(string $rawSql): string
    {
        return 'SELECT CASE WHEN EXISTS(' . $rawSql . ') THEN 1 ELSE 0 END';
    }

    /**
     * Converts an abstract column type into a physical column type.
     *
     * The conversion is done using the type map specified in {@see typeMap}.
     * The following abstract column types are supported (using Mssql as an example to explain the corresponding
     * physical types):
     *
     * - `pk`: an auto-incremental primary key type, will be converted into "int(11) NOT NULL AUTO_INCREMENT PRIMARY
     *    KEY".
     * - `bigpk`: an auto-incremental primary key type, will be converted into "bigint(20) NOT NULL AUTO_INCREMENT
     *    PRIMARY KEY".
     * - `upk`: an unsigned auto-incremental primary key type, will be converted into "int(10) UNSIGNED NOT NULL
     *    AUTO_INCREMENT PRIMARY KEY".
     * - `char`: char type, will be converted into "char(1)".
     * - `string`: string type, will be converted into "varchar(255)".
     * - `text`: a long string type, will be converted into "text".
     * - `smallint`: a small integer type, will be converted into "smallint(6)".
     * - `integer`: integer type, will be converted into "int(11)".
     * - `bigint`: a big integer type, will be converted into "bigint(20)".
     * - `boolean`: boolean type, will be converted into "tinyint(1)".
     * - `float``: float number type, will be converted into "float".
     * - `decimal`: decimal number type, will be converted into "decimal".
     * - `datetime`: datetime type, will be converted into "datetime".
     * - `timestamp`: timestamp type, will be converted into "timestamp".
     * - `time`: time type, will be converted into "time".
     * - `date`: date type, will be converted into "date".
     * - `money`: money type, will be converted into "decimal(19,4)".
     * - `binary`: binary data type, will be converted into "blob".
     *
     * If the abstract type contains two or more parts separated by spaces (e.g. "string NOT NULL"), then only the first
     * part will be converted, and the rest of the parts will be appended to the converted result.
     *
     * For example, 'string NOT NULL' is converted to 'varchar(255) NOT NULL'.
     *
     * For some abstract types you can also specify a length or precision constraint by appending it in round
     * brackets directly to the type.
     *
     * For example `string(32)` will be converted into "varchar(32)" on a Mssql database. If the underlying DBMS does
     * not support this kind of constraints for a type it will be ignored.
     *
     * If a type cannot be found in {@see typeMap}, it will be returned without any change.
     *
     * @param ColumnSchemaBuilder|string $type abstract column type
     *
     * @return string physical column type.
     */
    public function getColumnType($type): string
    {
        $columnType = parent::getColumnType($type);

        /** remove unsupported keywords*/
        $columnType = preg_replace("/\s*comment '.*'/i", '', $columnType);
        return preg_replace('/ first$/i', '', $columnType);
    }

    /**
     * Extracts table alias if there is one or returns false
     *
     * @param string $table
     *
     * @return array|bool
     * @psalm-return array<array-key, string>|bool
     */
    protected function extractAlias(string $table): array|bool
    {
        if (preg_match('/^\[.*]$/', $table)) {
            return false;
        }

        return parent::extractAlias($table);
    }
}
