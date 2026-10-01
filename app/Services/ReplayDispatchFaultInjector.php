<?php

declare(strict_types=1);

namespace App\Services;

class ReplayDispatchFaultInjector
{
    public function afterReceiptWrite(): void {}

    public function afterDeliveryWrite(): void {}

    public function afterOutboxWrite(): void {}
}
