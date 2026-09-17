<?php

namespace App\Contracts;

use App\Models\ExecutionCommand;

interface ExecutionAdapter
{
    /** @return array{accepted:bool,fill_price:?string,filled_at:?string,reason:?string} */
    public function execute(ExecutionCommand $command): array;
}
