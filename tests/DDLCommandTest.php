<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql\Tests;

use Closure;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Mssql\DDLCommand;

/**
 * @group mysql
 */
final class DDLCommandTest extends TestCase
{
    public function addDropChecksProvider(): array
    {
        $tableName = 'T_constraints_1';
        $name = 'CN_check';

        return [
            'drop' => [
                "ALTER TABLE {{{$tableName}}} DROP CONSTRAINT [[$name]]",
                static function (DDLCommand $ddl) use ($tableName, $name) {
                    return $ddl->dropCheck($name, $tableName);
                },
            ],
            'add' => [
                "ALTER TABLE {{{$tableName}}} ADD CONSTRAINT [[$name]] CHECK ([[C_not_null]] > 100)",
                static function (DDLCommand $ddl) use ($tableName, $name) {
                    return $ddl->addCheck($name, $tableName, '[[C_not_null]] > 100');
                },
            ],
        ];
    }

    /**
     * @dataProvider addDropChecksProvider
     *
     * @param string $sql
     * @param Closure $builder
     */
    public function testAddDropCheck(string $sql, Closure $builder): void
    {
        $db = $this->getConnection();
        $this->assertSame($db->getQuoter()->quoteSql($sql), $builder(new DDLCommand($db->getQuoter())));
    }
}
