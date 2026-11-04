<?php

declare(strict_types=1);

namespace AutoFlow\Tests\Unit\CoreBundle\Security;

use AutoFlow\CoreBundle\Security\JwksKeyProvider;
use AutoFlow\CoreBundle\Security\JwtAuthenticationSubscriber;
use AutoFlow\CoreBundle\Security\JwtTokenVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class JwtAuthenticationSubscriberTest extends TestCase
{
    public function testItProtectsTheExactApiRootAndItsNestedPaths(): void
    {
        $subscriber = $this->createSubscriber();

        foreach (['/api', '/api/', '/api/offers'] as $path) {
            $event = $this->createRequestEvent($path);
            $subscriber->onKernelRequest($event);

            self::assertNotNull($event->getResponse(), sprintf('Expected %s to be protected.', $path));
            self::assertSame(401, $event->getResponse()->getStatusCode());
            self::assertSame('Bearer', $event->getResponse()->headers->get('WWW-Authenticate'));
        }
    }

    public function testItDoesNotProtectPathsThatOnlyShareTheApiPrefixText(): void
    {
        $subscriber = $this->createSubscriber();
        $event = $this->createRequestEvent('/apiary');

        $subscriber->onKernelRequest($event);

        self::assertNull($event->getResponse());
    }

    private function createSubscriber(): JwtAuthenticationSubscriber
    {
        $keyProvider = new JwksKeyProvider(new MockHttpClient(), 'https://identity.example.test/jwks');

        return new JwtAuthenticationSubscriber(
            new JwtTokenVerifier($keyProvider, 'https://identity.example.test/realms/autoflow', 'autoflow-test'),
            true,
            ['/api/'],
            [],
        );
    }

    private function createRequestEvent(string $path): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request(server: ['REQUEST_URI' => $path, 'REQUEST_METHOD' => 'GET']);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
