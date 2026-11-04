<?php

declare(strict_types=1);

namespace AutoFlow\Tests\CoreBundle;

use AutoFlow\CoreBundle\Http\ProblemDetailsFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsFactoryTest extends TestCase
{
    public function testItCreatesAnRfcProblemDetailsResponse(): void
    {
        $response = (new ProblemDetailsFactory())->create(
            422,
            'Unprocessable Content',
            'The requested vehicle configuration is invalid.',
            'https://api.autoflow.example/problems/invalid-vehicle-configuration',
            '/api/v1/quotes',
            ['errorCode' => 'INVALID_CONFIGURATION'],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame(422, $payload['status']);
        self::assertSame('INVALID_CONFIGURATION', $payload['errorCode']);
    }

    public function testItRejectsNonErrorHttpStatuses(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProblemDetailsFactory())->create(200, 'OK');
    }

    public function testItDoesNotAllowExtensionsToOverrideStandardMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProblemDetailsFactory())->create(400, 'Bad Request', extensions: [
            'status' => 200,
        ]);
    }
}
