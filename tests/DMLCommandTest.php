<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\Tests;

use InvalidArgumentException;
use Yiisoft\Arrays\ArrayHelper;
use Yiisoft\Db\Exception\Exception;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Expression\Expression;
use Yiisoft\Db\Mssql\ColumnSchema;
use Yiisoft\Db\Mssql\DMLCommand;
use Yiisoft\Db\Query\Query;

/**
 * @group mysql
 */
final class DMLCommandTest extends TestCase
{
    public function insertProvider()
    {
        return [
            'regular-values' => [
                'customer',
                [
                    'email' => 'test@example.com',
                    'name' => 'silverfire',
                    'address' => 'Kyiv {{city}}, Ukraine',
                    'is_active' => false,
                    'related_id' => null,
                ],
                [],
                $this->replaceQuotes('SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [email] varchar(128) , [name] varchar(128) NULL, [address] text NULL, [status] int NULL, [profile_id] int NULL);' .
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]]) OUTPUT INSERTED.* INTO @temporary_inserted VALUES (:qp0, :qp1, :qp2, :qp3, :qp4);' .
                    'SELECT * FROM @temporary_inserted'),
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'silverfire',
                    ':qp2' => 'Kyiv {{city}}, Ukraine',
                    ':qp3' => false,
                    ':qp4' => null,
                ],
            ],
            'params-and-expressions' => [
                '{{%type}}',
                [
                    '{{%type}}.[[related_id]]' => null,
                    '[[time]]' => new Expression('now()'),
                ],
                [],
                'SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([int_col] int , [int_col2] int NULL, [tinyint_col] tinyint NULL, [smallint_col] smallint NULL, [char_col] char(100) , [char_col2] varchar(100) NULL, [char_col3] text NULL, [float_col] decimal , [float_col2] float NULL, [blob_col] varbinary(MAX) NULL, [numeric_col] decimal NULL, [time] datetime , [bool_col] tinyint , [bool_col2] tinyint NULL);' .
                'INSERT INTO {{%type}} ({{%type}}.[[related_id]], [[time]]) OUTPUT INSERTED.* INTO @temporary_inserted VALUES (:qp0, now());' .
                'SELECT * FROM @temporary_inserted',
                [
                    ':qp0' => null,
                ],
            ],
            'carry passed params' => [
                'customer',
                [
                    'email' => 'test@example.com',
                    'name' => 'sergeymakinen',
                    'address' => '{{city}}',
                    'is_active' => false,
                    'related_id' => null,
                    'col' => new Expression('CONCAT(:phFoo, :phBar)', [':phFoo' => 'foo']),
                ],
                [':phBar' => 'bar'],
                $this->replaceQuotes('SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [email] varchar(128) , [name] varchar(128) NULL, [address] text NULL, [status] int NULL, [profile_id] int NULL);' .
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]], [[col]]) OUTPUT INSERTED.* INTO @temporary_inserted VALUES (:qp1, :qp2, :qp3, :qp4, :qp5, CONCAT(:phFoo, :phBar));' .
                    'SELECT * FROM @temporary_inserted'),
                [
                    ':phBar' => 'bar',
                    ':qp1' => 'test@example.com',
                    ':qp2' => 'sergeymakinen',
                    ':qp3' => '{{city}}',
                    ':qp4' => false,
                    ':qp5' => null,
                    ':phFoo' => 'foo',
                ],
            ],
            'carry passed params (query)' => [
                'customer',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'name',
                        'address',
                        'is_active',
                        'related_id',
                    ])
                    ->from('customer')
                    ->where([
                        'email' => 'test@example.com',
                        'name' => 'sergeymakinen',
                        'address' => '{{city}}',
                        'is_active' => false,
                        'related_id' => null,
                        'col' => new Expression('CONCAT(:phFoo, :phBar)', [':phFoo' => 'foo']),
                    ]),
                [':phBar' => 'bar'],
                $this->replaceQuotes('SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [email] varchar(128) , [name] varchar(128) NULL, [address] text NULL, [status] int NULL, [profile_id] int NULL);' .
                    'INSERT INTO [[customer]] ([[email]], [[name]], [[address]], [[is_active]], [[related_id]]) OUTPUT INSERTED.* INTO @temporary_inserted SELECT [[email]], [[name]], [[address]], [[is_active]], [[related_id]] FROM [[customer]] WHERE ([[email]]=:qp1) AND ([[name]]=:qp2) AND ([[address]]=:qp3) AND ([[is_active]]=:qp4) AND ([[related_id]] IS NULL) AND ([[col]]=CONCAT(:phFoo, :phBar));' .
                    'SELECT * FROM @temporary_inserted'),
                [
                    ':phBar' => 'bar',
                    ':qp1' => 'test@example.com',
                    ':qp2' => 'sergeymakinen',
                    ':qp3' => '{{city}}',
                    ':qp4' => false,
                    ':phFoo' => 'foo',
                ],
            ],
        ];
    }

    /**
     * @dataProvider insertProvider
     *
     * @param string $table
     * @param array|ColumnSchema $columns
     * @param array $params
     * @param string $expectedSQL
     * @param array $expectedParams
     */
    public function testInsert(string $table, $columns, array $params, string $expectedSQL, array $expectedParams): void
    {
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $this->assertSame($expectedSQL, $dml->insert($table, $columns, $params));
        $this->assertSame($expectedParams, $params);
    }

    public function updateProvider(): array
    {
        return [
            [
                'customer',
                [
                    'status' => 1,
                    'updated_at' => new Expression('now()'),
                ],
                [
                    'id' => 100,
                ],
                $this->replaceQuotes(
                    'UPDATE [[customer]] SET [[status]]=:qp0, [[updated_at]]=now() WHERE [[id]]=:qp1'
                ),
                [
                    ':qp0' => 1,
                    ':qp1' => 100,
                ],
            ],
        ];
    }

    /**
     * @dataProvider updateProvider
     *
     * @param string $table
     * @param array $columns
     * @param array|string $condition
     * @param string $expectedSQL
     * @param array $expectedParams
     */
    public function testUpdate(
        string $table,
        array $columns,
        $condition,
        string $expectedSQL,
        array $expectedParams
    ): void {
        $actualParams = [];
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $actualSQL = $dml->update($table, $columns, $condition, $actualParams);
        $this->assertSame($expectedSQL, $actualSQL);
        $this->assertSame($expectedParams, $actualParams);
    }

    public function upsertProvider(): array
    {
        $concreteData = [
            'regular values' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (VALUES (:qp0, :qp1, :qp2, :qp3)) AS [EXCLUDED] ([email],'
                . ' [address], [status], [profile_id]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN MATCHED THEN'
                . ' UPDATE SET [address]=[EXCLUDED].[address], [status]=[EXCLUDED].[status], [profile_id]'
                . '=[EXCLUDED].[profile_id] WHEN NOT MATCHED THEN INSERT ([email], [address], [status], [profile_id])'
                . ' VALUES ([EXCLUDED].[email], [EXCLUDED].[address], [EXCLUDED].[status], [EXCLUDED].[profile_id]);',
            ],

            'regular values with update part' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (VALUES (:qp0, :qp1, :qp2, :qp3)) AS [EXCLUDED] ([email],'
                . ' [address], [status], [profile_id]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN MATCHED THEN'
                . ' UPDATE SET [address]=:qp4, [status]=:qp5, [orders]=T_upsert.orders + 1 WHEN NOT MATCHED THEN'
                . ' INSERT ([email], [address], [status], [profile_id]) VALUES ([EXCLUDED].[email],'
                . ' [EXCLUDED].[address], [EXCLUDED].[status], [EXCLUDED].[profile_id]);',
            ],

            'regular values without update part' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (VALUES (:qp0, :qp1, :qp2, :qp3)) AS [EXCLUDED] ([email],'
                . ' [address], [status], [profile_id]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN NOT MATCHED THEN'
                . ' INSERT ([email], [address], [status], [profile_id]) VALUES ([EXCLUDED].[email],'
                . ' [EXCLUDED].[address], [EXCLUDED].[status], [EXCLUDED].[profile_id]);',
            ],

            'query' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (SELECT [email], 2 AS [status] FROM [customer] WHERE'
                . ' [name]=:qp0 ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY) AS [EXCLUDED] ([email],'
                . ' [status]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN MATCHED THEN UPDATE SET'
                . ' [status]=[EXCLUDED].[status] WHEN NOT MATCHED THEN INSERT ([email], [status]) VALUES'
                . ' ([EXCLUDED].[email], [EXCLUDED].[status]);',
            ],

            'query with update part' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (SELECT [email], 2 AS [status] FROM [customer] WHERE'
                . ' [name]=:qp0 ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY) AS [EXCLUDED] ([email],'
                . ' [status]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN MATCHED THEN UPDATE SET'
                . ' [address]=:qp1, [status]=:qp2, [orders]=T_upsert.orders + 1 WHEN NOT MATCHED THEN'
                . ' INSERT ([email], [status]) VALUES ([EXCLUDED].[email], [EXCLUDED].[status]);',
            ],

            'query without update part' => [
                3 => 'MERGE [T_upsert] WITH (HOLDLOCK) USING (SELECT [email], 2 AS [status] FROM [customer] WHERE'
                . ' [name]=:qp0 ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 1 ROWS ONLY) AS [EXCLUDED] ([email],'
                . ' [status]) ON ([T_upsert].[email]=[EXCLUDED].[email]) WHEN NOT MATCHED THEN INSERT ([email],'
                . ' [status]) VALUES ([EXCLUDED].[email], [EXCLUDED].[status]);',
            ],

            'values and expressions' => [
                3 => 'SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [ts] int NULL, [email] varchar(128)'
                . ' , [recovery_email] varchar(128) NULL, [address] text NULL, [status] tinyint , [orders] int ,'
                . ' [profile_id] int NULL);'
                . 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) OUTPUT INSERTED.*'
                . ' INTO @temporary_inserted VALUES (:qp0, now());'
                . 'SELECT * FROM @temporary_inserted',
            ],

            'values and expressions with update part' => [
                3 => 'SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [ts] int NULL, [email] varchar(128)'
                . ' , [recovery_email] varchar(128) NULL, [address] text NULL, [status] tinyint , [orders] int ,'
                . ' [profile_id] int NULL);'
                . 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) OUTPUT INSERTED.*'
                . ' INTO @temporary_inserted VALUES (:qp0, now());'
                . 'SELECT * FROM @temporary_inserted',
            ],

            'values and expressions without update part' => [
                3 => 'SET NOCOUNT ON;DECLARE @temporary_inserted TABLE ([id] int , [ts] int NULL, [email] varchar(128)'
                . ' , [recovery_email] varchar(128) NULL, [address] text NULL, [status] tinyint , [orders] int ,'
                . ' [profile_id] int NULL);'
                . 'INSERT INTO {{%T_upsert}} ({{%T_upsert}}.[[email]], [[ts]]) OUTPUT INSERTED.*'
                . ' INTO @temporary_inserted VALUES (:qp0, now());'
                . 'SELECT * FROM @temporary_inserted',
            ],

            'query, values and expressions with update part' => [
                3 => 'MERGE {{%T_upsert}} WITH (HOLDLOCK) USING (SELECT :phEmail AS [email], now() AS [[time]]) AS'
                . ' [EXCLUDED] ([email], [[time]]) ON ({{%T_upsert}}.[email]=[EXCLUDED].[email]) WHEN MATCHED THEN'
                . ' UPDATE SET [ts]=:qp1, [[orders]]=T_upsert.orders + 1 WHEN NOT MATCHED THEN INSERT ([email],'
                . ' [[time]]) VALUES ([EXCLUDED].[email], [EXCLUDED].[[time]]);',
            ],

            'query, values and expressions without update part' => [
                3 => 'MERGE {{%T_upsert}} WITH (HOLDLOCK) USING (SELECT :phEmail AS [email], now() AS [[time]])'
                . ' AS [EXCLUDED] ([email], [[time]]) ON ({{%T_upsert}}.[email]=[EXCLUDED].[email]) WHEN MATCHED THEN'
                . ' UPDATE SET [ts]=:qp1, [[orders]]=T_upsert.orders + 1 WHEN NOT MATCHED THEN INSERT ([email],'
                . ' [[time]]) VALUES ([EXCLUDED].[email], [EXCLUDED].[[time]]);',
            ],
            'no columns to update' => [
                3 => 'MERGE [T_upsert_1] WITH (HOLDLOCK) USING (VALUES (:qp0)) AS [EXCLUDED] ([a]) ON'
                . ' ([T_upsert_1].[a]=[EXCLUDED].[a]) WHEN NOT MATCHED THEN INSERT ([a]) VALUES ([EXCLUDED].[a]);',
            ],
        ];

        $newData = $this->upsertProviderTrait();

        foreach ($concreteData as $testName => $data) {
            $newData[$testName] = array_replace($newData[$testName], $data);
        }

        return $newData;
    }

    public function upsertProviderTrait(): array
    {
        return [
            'regular values' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                true,
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                ],
            ],
            'regular values with update part' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                [
                    'address' => 'foo {{city}}',
                    'status' => 2,
                    'orders' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                    ':qp4' => 'foo {{city}}',
                    ':qp5' => 2,
                ],
            ],
            'regular values without update part' => [
                'T_upsert',
                [
                    'email' => 'test@example.com',
                    'address' => 'bar {{city}}',
                    'status' => 1,
                    'profile_id' => null,
                ],
                false,
                null,
                [
                    ':qp0' => 'test@example.com',
                    ':qp1' => 'bar {{city}}',
                    ':qp2' => 1,
                    ':qp3' => null,
                ],
            ],
            'query' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                true,
                null,
                [
                    ':qp0' => 'user1',
                ],
            ],
            'query with update part' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                [
                    'address' => 'foo {{city}}',
                    'status' => 2,
                    'orders' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'user1',
                    ':qp1' => 'foo {{city}}',
                    ':qp2' => 2,
                ],
            ],
            'query without update part' => [
                'T_upsert',
                (new Query($this->getConnection()))
                    ->select([
                        'email',
                        'status' => new Expression('2'),
                    ])
                    ->from('customer')
                    ->where(['name' => 'user1'])
                    ->limit(1),
                false,
                null,
                [
                    ':qp0' => 'user1',
                ],
            ],
            'values and expressions' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                true,
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'values and expressions with update part' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                [
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'values and expressions without update part' => [
                '{{%T_upsert}}',
                [
                    '{{%T_upsert}}.[[email]]' => 'dynamic@example.com',
                    '[[ts]]' => new Expression('now()'),
                ],
                false,
                null,
                [
                    ':qp0' => 'dynamic@example.com',
                ],
            ],
            'query, values and expressions with update part' => [
                '{{%T_upsert}}',
                (new Query($this->getConnection()))
                    ->select([
                        'email' => new Expression(':phEmail', [':phEmail' => 'dynamic@example.com']),
                        '[[time]]' => new Expression('now()'),
                    ]),
                [
                    'ts' => 0,
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':phEmail' => 'dynamic@example.com',
                    ':qp1' => 0,
                ],
            ],
            'query, values and expressions without update part' => [
                '{{%T_upsert}}',
                (new Query($this->getConnection()))
                    ->select([
                        'email' => new Expression(':phEmail', [':phEmail' => 'dynamic@example.com']),
                        '[[time]]' => new Expression('now()'),
                    ]),
                [
                    'ts' => 0,
                    '[[orders]]' => new Expression('T_upsert.orders + 1'),
                ],
                null,
                [
                    ':phEmail' => 'dynamic@example.com',
                    ':qp1' => 0,
                ],
            ],
            'no columns to update' => [
                'T_upsert_1',
                [
                    'a' => 1,
                ],
                false,
                null,
                [
                    ':qp0' => 1,
                ],
            ],
        ];
    }

    /**
     * @dataProvider upsertProvider
     *
     * @param string $table
     * @param array|ColumnSchema|Query $insertColumns
     * @param array|bool|null $updateColumns
     * @param string|string[] $expectedSQL
     * @param array|string $expectedParams
     *
     * @throws Exception|NotSupportedException
     */
    public function testUpsert(
        string $table,
        array|ColumnSchema|Query $insertColumns,
        array|bool|null $updateColumns,
        array|string $expectedSQL,
        array $expectedParams
    ): void {
        $actualParams = [];
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());
        $actualSQL = $dml->upsert($table, $insertColumns, $updateColumns, $actualParams);

        if (is_string($expectedSQL)) {
            $this->assertSame($expectedSQL, $actualSQL);
        } else {
            $this->assertContains($actualSQL, $expectedSQL);
        }

        if (ArrayHelper::isAssociative($expectedParams)) {
            $this->assertSame($expectedParams, $actualParams);
        } else {
            $this->assertIsOneOf($actualParams, $expectedParams);
        }
    }

    public function testUpsertVarbinary()
    {
        $db = $this->getConnection();
        $dml = new DMLCommand($db->getQueryBuilder(), $db->getQuoter(), $db->getSchema());

        $testData = json_encode(['test' => 'string', 'test2' => 'integer']);
        $params = [];

        $sql = $dml->upsert(
            'T_upsert_varbinary',
            ['id' => 1, 'blob_col' => $testData],
            ['blob_col' => $testData],
            $params
        );

        $result = $db->createCommand($sql, $params)->execute();
        $this->assertEquals(1, $result);

        $query = (new Query($db))->select(['blob_col as blob_col'])->from('T_upsert_varbinary')->where(['id' => 1]);
        $resultData = $query->createCommand()->queryOne();
        $this->assertEquals($testData, $resultData['blob_col']);
    }
}
