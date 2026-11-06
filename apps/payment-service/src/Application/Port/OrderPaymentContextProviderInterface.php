<?php

declare(strict_types=1);

namespace App\Application\Port;

interface OrderPaymentContextProviderInterface
{
    /** @return array{orderId:string,tenantId:string,status:string,amountMinor:int,currency:string} */
    public function getPayableContext(string $tenantId, string $orderId): array;
}
