<?php

declare(strict_types=1);

namespace AutoFlow\\Tests\\CoreBundle;

use AutoFlow\\CoreBundle\\Transport\\Http\\Response\\ApiResponseFactory;
use PHPUnit\\Framework\\TestCase;
use Symfony\\Component\\HttpFoundation\\JsonResponse;

final class ApiResponseFactoryTest extends TestCase
{
    public function testItCreatesAStandardSuccessResponse(): void
    {
        $response = (new ApiResponseFactory())->success(
            ['quoteId' => 'quote-123'],
            JsonResponse::HTTP_CREATED,
            ['page' => 1],
        );

        self::assertSame(JsonResponse::HTTP_CREATED, $response->getStatusCode());
        self::assertSame(
            ['data' => ['quoteId' => 'quote-123'], 'meta' => ['page' => 1]],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testItOmitsEmptyMetadata(): void
    {
        $response = (new ApiResponseFactory())->success(['ok' => true]);

        self::assertSame(
            ['data' => ['ok' => true]],
            json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
