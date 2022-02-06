<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\Tests;

use Closure;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Mssql\PDO\QueryBuilderPDOMssql;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Query\QueryBuilderInterface;
use Yiisoft\Db\TestSupport\TestQueryBuilderTrait;
use Yiisoft\Db\TestSupport\TraversableObject;

use function array_replace;

/**
 * @group mssql
 */
final class QueryBuilderTest extends TestCase
{
    use TestQueryBuilderTrait;

    /**
     * @var string ` ESCAPE 'char'` part of a LIKE condition SQL.
     */
    protected string $likeEscapeCharSql = '';

    /**
     * @var array map of values to their replacements in LIKE query params.
     */
    protected array $likeParameterReplacements = [
        '\%' => '[%]',
        '\_' => '[_]',
        '[' => '[[]',
        ']' => '[]]',
        '\\\\' => '[\\]',
    ];

    /**
     * @return QueryBuilderInterface
     */
    protected function getQueryBuilder(ConnectionInterface $db): QueryBuilderInterface
    {
        return new QueryBuilderPDOMssql($db);
    }

    protected function getCommmentsFromTable(string $table): array
    {
        $db = $this->getConnection();
        $sql = "SELECT *
            FROM fn_listextendedproperty (
                N'MS_description',
                'SCHEMA', N'dbo',
                'TABLE', N" . $db->getQuoter()->quoteValue($table) . ',
                DEFAULT, DEFAULT
        )';
        return $db->createCommand($sql)->queryAll();
    }

    protected function getCommentsFromColumn(string $table, string $column): array
    {
        $db = $this->getConnection();
        $sql = "SELECT *
            FROM fn_listextendedproperty (
                N'MS_description',
                'SCHEMA', N'dbo',
                'TABLE', N" . $db->getQuoter()->quoteValue($table) . ",
                'COLUMN', N" . $db->getQuoter()->quoteValue($column) . '
        )';
        return $db->createCommand($sql)->queryAll();
    }

    protected function runAddCommentOnTable(string $comment, string $table): int
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);
        $sql = $qb->addCommentOnTable($table, $comment);
        return $db->createCommand($sql)->execute();
    }

    protected function runAddCommentOnColumn(string $comment, string $table, string $column): int
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);
        $sql = $qb->addCommentOnColumn($table, $column, $comment);
        return $db->createCommand($sql)->execute();
    }

    protected function runDropCommentFromTable(string $table): int
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);
        $sql = $qb->dropCommentFromTable($table);
        return $db->createCommand($sql)->execute();
    }

    protected function runDropCommentFromColumn(string $table, string $column): int
    {
        $db = $this->getConnection();
        $qb = $this->getQueryBuilder($db);
        $sql = $qb->dropCommentFromColumn($table, $column);
        return $db->createCommand($sql)->execute();
    }

    public function testOffsetLimit(): void
    {
        $db = $this->getConnection();
        $expectedQuerySql = 'SELECT [id] FROM [example] ORDER BY (SELECT NULL) OFFSET 5 ROWS FETCH NEXT 10 ROWS ONLY';
        $expectedQueryParams = [];
        $query = new Query($db);
        $query->select('id')->from('example')->limit(10)->offset(5);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    public function testLimit(): void
    {
        $db = $this->getConnection();
        $expectedQuerySql = 'SELECT [id] FROM [example] ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 10 ROWS ONLY';
        $expectedQueryParams = [];
        $query = new Query($db);
        $query->select('id')->from('example')->limit(10);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    public function testOffset(): void
    {
        $db = $this->getConnection();
        $expectedQuerySql = 'SELECT [id] FROM [example] ORDER BY (SELECT NULL) OFFSET 10 ROWS';
        $expectedQueryParams = [];
        $query = new Query($db);
        $query->select('id')->from('example')->offset(10);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    public function testCommentAdditionOnTableAndOnColumn(): void
    {
        $table = 'profile';
        $tableComment = 'A comment for profile table.';
        $this->runAddCommentOnTable($tableComment, $table);

        $resultTable = $this->getCommmentsFromTable($table);
        $this->assertEquals([
            'objtype' => 'TABLE',
            'objname' => $table,
            'name' => 'MS_description',
            'value' => $tableComment,
        ], $resultTable[0]);

        $column = 'description';
        $columnComment = 'A comment for description column in profile table.';

        $this->runAddCommentOnColumn($columnComment, $table, $column);

        $resultColumn = $this->getCommentsFromColumn($table, $column);

        $this->assertEquals([
            'objtype' => 'COLUMN',
            'objname' => $column,
            'name' => 'MS_description',
            'value' => $columnComment,
        ], $resultColumn[0]);

        /* Add another comment to the same table to test update */
        $tableComment2 = 'Another comment for profile table.';

        $this->runAddCommentOnTable($tableComment2, $table);

        $result = $this->getCommmentsFromTable($table);

        $this->assertEquals([
            'objtype' => 'TABLE',
            'objname' => $table,
            'name' => 'MS_description',
            'value' => $tableComment2,
        ], $result[0]);

        /* Add another comment to the same column to test update */
        $columnComment2 = 'Another comment for description column in profile table.';

        $this->runAddCommentOnColumn($columnComment2, $table, $column);

        $result = $this->getCommentsFromColumn($table, $column);

        $this->assertEquals([
            'objtype' => 'COLUMN',
            'objname' => $column,
            'name' => 'MS_description',
            'value' => $columnComment2,
        ], $result[0]);
    }

    public function testCommentAdditionOnQuotedTableOrColumn(): void
    {
        $table = 'stranger \'table';
        $tableComment = 'A comment for stranger \'table.';
        $this->runAddCommentOnTable($tableComment, $table);

        $resultTable = $this->getCommmentsFromTable($table);
        $this->assertEquals([
            'objtype' => 'TABLE',
            'objname' => $table,
            'name' => 'MS_description',
            'value' => $tableComment,
        ], $resultTable[0]);

        $column = 'stranger \'field';
        $columnComment = 'A comment for stranger \'field column in stranger \'table.';
        $this->runAddCommentOnColumn($columnComment, $table, $column);

        $resultColumn = $this->getCommentsFromColumn($table, $column);
        $this->assertEquals([
            'objtype' => 'COLUMN',
            'objname' => $column,
            'name' => 'MS_description',
            'value' => $columnComment,
        ], $resultColumn[0]);
    }

    public function testCommentRemovalFromTableAndFromColumn(): void
    {
        $table = 'profile';
        $tableComment = 'A comment for profile table.';
        $this->runAddCommentOnTable($tableComment, $table);
        $this->runDropCommentFromTable($table);

        $result = $this->getCommmentsFromTable($table);
        $this->assertEquals([], $result);

        $column = 'description';
        $columnComment = 'A comment for description column in profile table.';
        $this->runAddCommentOnColumn($columnComment, $table, $column);
        $this->runDropCommentFromColumn($table, $column);

        $result = $this->getCommentsFromColumn($table, $column);
        $this->assertEquals([], $result);
    }

    public function testCommentRemovalFromQuotedTableOrColumn(): void
    {
        $table = 'stranger \'table';
        $tableComment = 'A comment for stranger \'table.';
        $this->runAddCommentOnTable($tableComment, $table);
        $this->runDropCommentFromTable($table);

        $result = $this->getCommmentsFromTable($table);
        $this->assertEquals([], $result);

        $column = 'stranger \'field';
        $columnComment = 'A comment for stranger \'field in stranger \'table.';
        $this->runAddCommentOnColumn($columnComment, $table, $column);
        $this->runDropCommentFromColumn($table, $column);

        $result = $this->getCommentsFromColumn($table, $column);
        $this->assertEquals([], $result);
    }

    /**
     * @dataProvider addDropForeignKeysProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropForeignKey(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider addDropPrimaryKeysProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropPrimaryKey(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider addDropUniquesProviderTrait
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropUnique(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    public function batchInsertProvider()
    {
        $data = $this->batchInsertProviderTrait();

        $data['escape-danger-chars']['expected'] = 'INSERT INTO [customer] ([address])'
            . " VALUES ('SQL-danger chars are escaped: ''); --')";

        $data['bool-false, bool2-null']['expected'] = 'INSERT INTO [type] ([bool_col], [bool_col2]) VALUES (0, NULL)';

        $data['bool-false, time-now()']['expected'] = 'INSERT INTO {{%type}} ({{%type}}.[[bool_col]], [[time]])'
            . ' VALUES (0, now())';

        return $data;
    }

    /**
     * @dataProvider batchInsertProvider
     *
     * @param string $table
     * @param array $columns
     * @param array $value
     * @param string $expected
     */
    public function testBatchInsert(string $table, array $columns, array $value, string $expected): void
    {
        $db = $this->getConnection();
        $queryBuilder = $this->getQueryBuilder($db);
        $sql = $queryBuilder->batchInsert($table, $columns, $value);
        $this->assertEquals($expected, $sql);
    }

    public function buildConditionsProvider(): array
    {
        $data = $this->buildConditionsProviderTrait();

        $data['composite in'] = [
            ['in', ['id', 'name'], [['id' => 1, 'name' => 'oy']]],
            '(([id] = :qp0 AND [name] = :qp1))',
            [':qp0' => 1, ':qp1' => 'oy'],
        ];
        $data['composite in using array objects'] = [
            ['in', new TraversableObject(['id', 'name']), new TraversableObject([
                ['id' => 1, 'name' => 'oy'],
                ['id' => 2, 'name' => 'yo'],
            ])],
            '(([id] = :qp0 AND [name] = :qp1) OR ([id] = :qp2 AND [name] = :qp3))',
            [':qp0' => 1, ':qp1' => 'oy', ':qp2' => 2, ':qp3' => 'yo'],
        ];

        return $data;
    }

    /**
     * @dataProvider buildConditionsProvider
     *
     * @param array|ExpressionInterface $condition
     * @param string $expected
     * @param array $expectedParams
     */
    public function testBuildCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildFilterConditionProviderTrait
     *
     * @param array $condition
     * @param string $expected
     * @param array $expectedParams
     */
    public function testBuildFilterCondition(array $condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->filterWhere($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    public function buildFromDataProvider(): array
    {
        $data = $this->buildFromDataProviderTrait();

        $data[] = ['[test]', '[[test]]'];

        $data[] = ['[test] [t1]', '[[test]] [[t1]]'];

        $data[] = ['[table.name]', '[[table.name]]'];

        $data[] = ['[table.name.with.dots]', '[[table.name.with.dots]]'];

        $data[] = ['[table name]', '[[table name]]'];

        $data[] = ['[table name with spaces]', '[[table name with spaces]]'];

        return $data;
    }

    /**
     * @dataProvider buildFromDataProviderTrait
     *
     * @param string $table
     * @param string $expected
     *
     * @throws Exception
     */
    public function testBuildFrom(string $table, string $expected): void
    {
        $db = $this->getConnection();
        $params = [];
        $sql = $this->getQueryBuilder($db)->buildFrom([$table], $params);
        $this->assertEquals('FROM ' . $this->replaceQuotes($expected), $sql);
    }

    /**
     * @dataProvider buildLikeConditionsProviderTrait
     *
     * @param array|object $condition
     * @param string $expected
     * @param array $expectedParams
     */
    public function testBuildLikeCondition($condition, string $expected, array $expectedParams): void
    {
        $db = $this->getConnection();
        $query = (new Query($db))->where($condition);
        [$sql, $params] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals('SELECT *' . (empty($expected) ? '' : ' WHERE ' . $this->replaceQuotes($expected)), $sql);
        $this->assertEquals($expectedParams, $params);
    }

    /**
     * @dataProvider buildExistsParamsProviderTrait
     *
     * @param string $cond
     * @param string $expectedQuerySql
     */
    public function testBuildWhereExists(string $cond, string $expectedQuerySql): void
    {
        $db = $this->getConnection();
        $expectedQueryParams = [];
        $subQuery = new Query($db);
        $subQuery->select('1')->from('Website w');
        $query = new Query($db);
        $query->select('id')->from('TotalExample t')->where([$cond, $subQuery]);
        [$actualQuerySql, $actualQueryParams] = $this->getQueryBuilder($db)->build($query);
        $this->assertEquals($expectedQuerySql, $actualQuerySql);
        $this->assertEquals($expectedQueryParams, $actualQueryParams);
    }

    /**
     * @dataProvider createDropIndexesProviderTrait
     *
     * @param string $sql
     */
    public function testCreateDropIndex(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder($this->getQueryBuilder($db)));
    }

    /**
     * @dataProvider deleteProviderTrait
     *
     * @param string $table
     * @param array|string $condition
     * @param string $expectedSQL
     * @param array $expectedParams
     */
    public function testDelete(string $table, $condition, string $expectedSQL, array $expectedParams): void
    {
        $db = $this->getConnection();
        $actualParams = [];
        $actualSQL = $this->getQueryBuilder($db)->delete($table, $condition, $actualParams);
        $this->assertSame($expectedSQL, $actualSQL);
        $this->assertSame($expectedParams, $actualParams);
    }
}
