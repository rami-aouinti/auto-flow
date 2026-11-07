<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http\Controller;

use App\Presentation\Http\Controller\DocuSignWebhookController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DocuSignWebhookControllerTest extends TestCase
{
    private const HMAC_KEY = 'test-docusign-connect-secret';

    public function testItRejectsAnInvalidHmacBeforeAccessingTheDatabase(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');

        $controller = new DocuSignWebhookController($db, self::HMAC_KEY);
        $request = Request::create(
            '/webhooks/docusign',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode([
                'event' => 'envelope-completed',
                'data' => ['envelopeId' => 'envelope-123'],
            ], JSON_THROW_ON_ERROR),
        );

        $response = $controller($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testItAcknowledgesAValidButUnknownEnvelopeWithoutLeakingTenantInformation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects(self::once())
            ->method('fetchAssociative')
            ->with(
                'SELECT tenant_id, contract_id
                 FROM public.contract_find_tenant_for_envelope(:envelope_id)',
                ['envelope_id' => 'envelope-unknown'],
            )
            ->willReturn(false);

        $payload = json_encode([
            'event' => 'envelope-completed',
            'generatedDateTime' => '2026-11-07T22:00:00Z',
            'data' => [
                'envelopeId' => 'envelope-unknown',
                'envelopeSummary' => ['status' => 'completed'],
            ],
        ], JSON_THROW_ON_ERROR);
        $signature = base64_encode(hash_hmac('sha256', $payload, self::HMAC_KEY, true));

        $controller = new DocuSignWebhookController($db, self::HMAC_KEY);
        $request = Request::create(
            '/webhooks/docusign',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DOCUSIGN_SIGNATURE_1' => $signature,
            ],
            content: $payload,
        );

        $response = $controller($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['received' => true], json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
