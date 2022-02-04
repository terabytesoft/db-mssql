<?php

declare(strict_types=1);

namespace Yiisoft\Db\Mssql;

use Yiisoft\Db\Command\DDLCommand as AbstractDDLCommand;
use Yiisoft\Db\Schema\QuoterInterface;

final class DDLCommand extends AbstractDDLCommand
{
    public function __construct(private QuoterInterface $quoter)
    {
        parent::__construct($quoter);
    }
}
