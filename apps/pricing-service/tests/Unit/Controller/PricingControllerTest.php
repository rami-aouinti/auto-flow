<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Application\CalculateQuote\CalculateQuoteHandler;
use App\Application\Port\Out\PricingPolicyRepositoryInterface;
use App\Application\Port\Out\PricingQuoteRepositoryInterface;
use App\Application\Port\Out\QuoteIdGeneratorInterface;
use App\Controller\PricingController;
use App\Domain\Service\LeasePaymentCalculator;
use App\Observability\PricingMetrics;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class PricingControllerTest extends TestCase
{
    public function testCalculateReturnsSnapshotAndSevenDayValidity(): void
    {
        $controller = new PricingController();
        $request = $this->request(json_encode([
            'vehicleId' => 'vehicle-demo-001',
            'vehiclePrice' => '30000.00',
            'downPayment' => '5000.00',
            'residualValue' => '12000.00',
            'currency' => 'EUR',
            'durationMonths' => 48,
            'annualInterestRateBasisPoints' => 450,
        ], JSON_THROW_ON_ERROR));
        $response = $controller->calculate(
            $request,
            $this->handler(true),
            $this->tenantContext($request),
            new PricingMetrics(),
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('vehicle-demo-001', $payload['vehicleId']);
        self::assertSame('30000.00', $payload['vehiclePrice']);
        self::assertSame('5000.00', $payload['downPayment']);
        $createdAt = new DateTimeImmutable($payload['createdAt']);
        $expiresAt = new DateTimeImmutable($payload['expiresAt']);
        self::assertSame(7, (int) $createdAt->diff($expiresAt)->format('%a'));
        self::assertSame('lease-annuity-v1', $payload['calculationVersion']);
    }

    public function testMalformedJsonReturnsStableBadRequestEnvelope(): void
    {
        $controller = new PricingController();
        $request = $this->request('{invalid');
        $response = $controller->calculate(
            $request,
            $this->handler(false),
            $this->tenantContext($request),
            new PricingMetrics(),
        );
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_json', $payload['error']);
    }

    public function testMissingFieldReturnsUnprocessableEntity(): void
    {
        $controller = new PricingController();
        $request = $this->request('{"vehicleId":"vehicle-demo-001"}');
        $response = $controller->calculate($request, $this->handler(false), $this->tenantContext($request), new PricingMetrics());

        self::assertSame(422, $response->getStatusCode());
    }

    private function request(string $content): Request
    {
        $request = Request::create(
            '/api/pricing/quotes',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            $content,
        );
        $request->attributes->set(
            AuthenticatedPrincipal::REQUEST_ATTRIBUTE,
            new AuthenticatedPrincipal('user-demo', 'tenant-demo', ['pricing:quote']),
        );
        return $request;
    }

    private function tenantContext(Request $request): TenantContext
    {
        $stack = new RequestStack();
        $stack->push($request);
        return new TenantContext($stack);
    }

    private function handler(bool $expectSave): CalculateQuoteHandler
    {
        $quotes = $this->createMock(PricingQuoteRepositoryInterface::class);
        $quotes->expects($expectSave ? self::once() : self::never())->method('save');
        $ids = $this->createMock(QuoteIdGeneratorInterface::class);
        $ids->method('generate')->willReturn('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $policies = $this->createMock(PricingPolicyRepositoryInterface::class);
        $policies->method('findActive')->with('tenant-demo')->willReturn(null);

        return new CalculateQuoteHandler(new LeasePaymentCalculator(), $quotes, $ids, $policies);
    }
}
