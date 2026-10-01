<?php

declare(strict_types=1);

namespace App\Services;

class ReplaySettlementFaultInjector
{
    public function beforeDispatchExceptionSettlement(): void {}

    public function beforeFailedJobSettlement(): void {}

    public function beforeHandledJobSettlement(): void {}
}
