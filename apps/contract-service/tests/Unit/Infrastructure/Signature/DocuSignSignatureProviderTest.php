<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Signature;

use App\Infrastructure\Signature\DocuSignSignatureProvider;
use DomainException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;

final class DocuSignSignatureProviderTest extends TestCase
{
    public function testItFailsClosedWhenDocuSignIsNotConfigured(): void
    {
        $provider = new DocuSignSignatureProvider(
            new MockHttpClient(),
            new ArrayAdapter(),
            '',
            '',
            '',
            '',
            '',
            '',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DocuSign is not configured');

        $provider->send('/does/not/exist.pdf', 'customer@example.test', 'Customer', 'signature-001');
    }

    public function testItRejectsAnInvalidRecipientEmailBeforeCallingTheProvider(): void
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'autoflow-contract-');
        self::assertIsString($pdfPath);
        file_put_contents($pdfPath, '%PDF-1.4 test');

        try {
            $provider = new DocuSignSignatureProvider(
                new MockHttpClient(),
                new ArrayAdapter(),
                'integration-key',
                'user-id',
                'account-id',
                'account-d.docusign.com',
                'https://demo.docusign.net/restapi',
                '/unused/private.pem',
            );

            $this->expectException(DomainException::class);
            $this->expectExceptionMessage('valid signing email');

            $provider->send($pdfPath, 'not-an-email', 'Customer', 'signature-002');
        } finally {
            @unlink($pdfPath);
        }
    }
}
