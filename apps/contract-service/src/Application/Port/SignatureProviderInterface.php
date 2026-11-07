<?php

declare(strict_types=1);

namespace App\\Application\\Port;

interface SignatureProviderInterface
{
    /**
     * @return array{provider:string,envelopeId:string,status:string}
     */
    public function send(
        string $pdfPath,
        string $recipientEmail,
        string $recipientName,
        string $idempotencyKey,
    ): array;
}
