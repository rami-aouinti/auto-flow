<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Transport;

use AutoFlow\CoreBundle\Security\InvalidTokenException;
use AutoFlow\CoreBundle\Security\JwksKeyProvider;
use AutoFlow\CoreBundle\Security\JwtTokenVerifier;
use AutoFlow\CoreBundle\Security\TenantPrincipalResolver;
use AutoFlow\CoreBundle\Transport\Grpc\GrpcRequestAuthenticator;
use PHPUnit\Framework\TestCase;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Symfony\Component\HttpClient\MockHttpClient;

final class GrpcRequestAuthenticatorTest extends TestCase
{
    public function testItRejectsGrpcCallsWithoutBearerMetadata(): void
    {
        $context = $this->createMock(ContextInterface::class);
        $context->method('getValue')->willReturn(null);
        $keyProvider = new JwksKeyProvider(
            new MockHttpClient(),
            'https://identity.example.test/jwks',
        );
        $authenticator = new GrpcRequestAuthenticator(
            new JwtTokenVerifier($keyProvider, 'https://identity.example.test/issuer', 'autoflow-pricing'),
            new TenantPrincipalResolver(),
        );

        $this->expectException(InvalidTokenException::class);

        $authenticator->authenticate($context, 'pricing:quote');
    }
}
