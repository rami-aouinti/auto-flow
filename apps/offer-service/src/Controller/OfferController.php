<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\AcceptOffer\AcceptOfferHandler;
use App\Application\CreateOffer\CreateOfferCommand;
use App\Application\CreateOffer\CreateOfferHandler;
use App\Application\GetOffer\GetOfferHandler;
use App\Application\GetOfferHistory\GetOfferHistoryHandler;
use App\Application\ListOffers\ListOffersHandler;
use App\Application\PublishOffer\PublishOfferHandler;
use App\Application\RejectOffer\RejectOfferHandler;
use App\Domain\Entity\Offer;
use App\Domain\Exception\ConcurrentOfferModification;
use App\Domain\Exception\InvalidOfferTransition;
use App\Domain\Exception\OfferNotFound;
use App\Domain\ValueObject\OfferId;
use App\Domain\ValueObject\OfferStatus;
use App\Security\KeycloakTokenExchangeProvider;
use App\Infrastructure\Pdf\OfferPdfGenerator;
use AutoFlow\CoreBundle\Context\TenantContext;
use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\RequiresScope;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use LogicException;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
#[AsController]
final readonly class OfferController
{
    public function __construct(
        private TenantContext $tenantContext,
        private CreateOfferHandler $createOfferHandler,
        private GetOfferHandler $getOfferHandler,
        private GetOfferHistoryHandler $getOfferHistoryHandler,
        private ListOffersHandler $listOffersHandler,
        private PublishOfferHandler $publishOfferHandler,
        private AcceptOfferHandler $acceptOfferHandler,
        private RejectOfferHandler $rejectOfferHandler,
        private int $offerValidityDays,
        private OfferPdfGenerator $offerPdfGenerator,
        private \App\Observability\OfferMetrics $offerMetrics,
    ) {
    }

    #[Route('/health', name: 'offer_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'service' => 'offer-service',
            'status' => 'ok',
        ]);
    }


    #[RequiresScope('offers:quote')]
    #[Route('/api/offers/{vehicleId}', name: 'offer_create', methods: ['POST'])]
    public function create(string $vehicleId, Request $request): JsonResponse
    {
        $span = Globals::tracerProvider()
            ->getTracer('autoflow.offer-service', '1.0.0')
            ->spanBuilder('offer.create')
            ->startSpan();
        $scope = $span->activate();

        try {
            if ($this->offerValidityDays < 1 || $this->offerValidityDays > 365) {
                throw new LogicException('Offer validity must be between 1 and 365 days.');
            }

            $payload = $request->toArray();
            $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

            if (!$principal instanceof AuthenticatedPrincipal) {
                throw new LogicException('A verified authenticated principal is required.');
            }

            $createdAt = new DateTimeImmutable();
            $expiresAt = $createdAt->modify(sprintf('+%d days', $this->offerValidityDays));

            if (!$expiresAt instanceof DateTimeImmutable) {
                throw new LogicException('Could not calculate the offer expiration date.');
            }

            $offer = ($this->createOfferHandler)(new CreateOfferCommand(
                tenantId: $this->tenantContext->requireTenantId(),
                createdBy: $principal->subject,
                subjectAccessToken: $this->requireBearerToken($request),
                vehicleId: $vehicleId,
                vehiclePrice: $this->requiredString($payload, 'vehiclePrice'),
                downPayment: $this->requiredString($payload, 'downPayment'),
                residualValue: $this->requiredString($payload, 'residualValue'),
                currency: $this->requiredString($payload, 'currency'),
                durationMonths: $this->requiredInteger($payload, 'durationMonths'),
                annualInterestRateBasisPoints: $this->requiredInteger(
                    $payload,
                    'annualInterestRateBasisPoints',
                ),
                createdAt: $createdAt,
                expiresAt: $expiresAt,
            ));

            $this->offerMetrics->recordLifecycleEvent('created');

            $span->setAttribute('offer.id', (string) $offer->id());
            $span->setAttribute('vehicle.id', $vehicleId);

            $response = new JsonResponse([
                'offerId' => (string) $offer->id(),
                'vehicleId' => $offer->pricingSnapshot()->vehicleId,
                'quoteId' => $offer->pricingSnapshot()->quoteId,
                'status' => $offer->status()->value,
                'pricing' => [
                    'monthlyAmount' => $offer->pricingSnapshot()->monthlyAmount,
                    'totalInstallmentsAmount' => $offer->pricingSnapshot()->totalInstallmentsAmount,
                    'totalCostIncludingDownPayment' => $offer->pricingSnapshot()->totalCostIncludingDownPayment,
                    'residualValue' => $offer->pricingSnapshot()->residualValue,
                    'currency' => $offer->pricingSnapshot()->currency,
                    'durationMonths' => $offer->pricingSnapshot()->durationMonths,
                    'annualInterestRateBasisPoints' => $offer->pricingSnapshot()->annualInterestRateBasisPoints,
                    'calculationVersion' => $offer->pricingSnapshot()->calculationVersion,
                ],
                'createdAt' => $offer->createdAt()->format(DATE_ATOM),
                'expiresAt' => $offer->expiresAt()->format(DATE_ATOM),
            ], JsonResponse::HTTP_CREATED);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse([
                'error' => 'invalid_offer_request',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        } catch (Throwable $exception) {
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->recordException($exception);

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    #[RequiresScope('offers:read')]
    #[Route('/api/offers', name: 'offer_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        $limit = $request->query->getInt('limit', 20);
        $offset = $request->query->getInt('offset', 0);
        $statusValue = $request->query->get('status');
        $searchValue = $request->query->get('search');

        if ($limit < 1 || $limit > 100 || $offset < 0 || $offset > 1000000) {
            return new JsonResponse([
                'error' => 'invalid_pagination',
                'message' => 'Limit must be between 1 and 100; offset must be between 0 and 1000000.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $status = null;
        if (is_string($statusValue) && trim($statusValue) !== '') {
            $status = OfferStatus::tryFrom(trim($statusValue));
            if ($status === null) {
                return new JsonResponse([
                    'error' => 'invalid_offer_status',
                    'message' => 'The requested offer status is not supported.',
                ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $search = is_string($searchValue) ? trim($searchValue) : null;
        if ($search !== null && strlen($search) > 100) {
            return new JsonResponse([
                'error' => 'invalid_search',
                'message' => 'Search must not exceed 100 characters.',
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = ($this->listOffersHandler)(
            $this->tenantContext->requireTenantId(),
            $principal->subject,
            $status,
            $search !== '' ? $search : null,
            $limit,
            $offset,
        );

        $response = new JsonResponse([
            'items' => array_map($this->offerPayload(...), $result['items']),
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('offers:read')]
    #[Route('/api/offers/{offerId}/history', name: 'offer_history', methods: ['GET'])]
    public function history(string $offerId, Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $history = ($this->getOfferHistoryHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
            );
        } catch (InvalidArgumentException) {
            $history = null;
        }

        if ($history === null) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse([
            'offerId' => $offerId,
            'items' => $history,
            'total' => count($history),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('offers:read')]
    #[Route('/api/offers/{offerId}', name: 'offer_show', methods: ['GET'])]
    public function show(string $offerId, Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $offer = ($this->getOfferHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
            );
        } catch (InvalidArgumentException) {
            $offer = null;
        }

        if ($offer === null) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new JsonResponse($this->offerPayload($offer));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('offers:read')]
    #[Route('/api/offers/{offerId}/pdf', name: 'offer_pdf', methods: ['GET'])]
    public function pdf(string $offerId, Request $request): Response
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $offer = ($this->getOfferHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
            );
        } catch (InvalidArgumentException) {
            $offer = null;
        }

        if ($offer === null) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $response = new Response($this->offerPdfGenerator->generate($offer));
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="autoflow-offer-%s.pdf"', (string) $offer->id()),
        );
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    #[RequiresScope('offers:quote')]
    #[Route('/api/offers/{offerId}/publish', name: 'offer_publish', methods: ['POST'])]
    public function publish(string $offerId, Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $offer = ($this->publishOfferHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
                new DateTimeImmutable(),
            );
        } catch (InvalidOfferTransition | ConcurrentOfferModification $exception) {
            return new JsonResponse([
                'error' => 'invalid_offer_transition',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_CONFLICT);
        } catch (OfferNotFound | InvalidArgumentException) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->offerMetrics->recordLifecycleEvent('published');
        $response = new JsonResponse($this->offerPayload($offer));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('offers:quote')]
    #[Route('/api/offers/{offerId}/accept', name: 'offer_accept', methods: ['POST'])]
    public function accept(string $offerId, Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $offer = ($this->acceptOfferHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
                new DateTimeImmutable(),
            );
        } catch (InvalidOfferTransition | ConcurrentOfferModification $exception) {
            return new JsonResponse([
                'error' => 'invalid_offer_transition',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_CONFLICT);
        } catch (OfferNotFound | InvalidArgumentException) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->offerMetrics->recordLifecycleEvent('accepted');
        $response = new JsonResponse($this->offerPayload($offer));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[RequiresScope('offers:quote')]
    #[Route('/api/offers/{offerId}/reject', name: 'offer_reject', methods: ['POST'])]
    public function reject(string $offerId, Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        try {
            $offer = ($this->rejectOfferHandler)(
                OfferId::fromString($offerId),
                $this->tenantContext->requireTenantId(),
                $principal->subject,
                new DateTimeImmutable(),
            );
        } catch (InvalidOfferTransition | ConcurrentOfferModification $exception) {
            return new JsonResponse([
                'error' => 'invalid_offer_transition',
                'message' => $exception->getMessage(),
            ], JsonResponse::HTTP_CONFLICT);
        } catch (OfferNotFound | InvalidArgumentException) {
            return new JsonResponse(['error' => 'offer_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        $this->offerMetrics->recordLifecycleEvent('rejected');
        $response = new JsonResponse($this->offerPayload($offer));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }



    /**
     * @return array<string, mixed>
     */
    private function offerPayload(Offer $offer): array
    {
        $snapshot = $offer->pricingSnapshot();

        return [
            'offerId' => (string) $offer->id(),
            'quoteId' => $snapshot->quoteId,
            'vehicleId' => $snapshot->vehicleId,
            'status' => $offer->status()->value,
            'publishedAt' => $offer->publishedAt()?->format(DATE_ATOM),
            'acceptedAt' => $offer->acceptedAt()?->format(DATE_ATOM),
            'rejectedAt' => $offer->rejectedAt()?->format(DATE_ATOM),
            'expiredAt' => $offer->expiredAt()?->format(DATE_ATOM),
            'pricing' => [
                'monthlyAmount' => $snapshot->monthlyAmount,
                'totalInstallmentsAmount' => $snapshot->totalInstallmentsAmount,
                'totalCostIncludingDownPayment' => $snapshot->totalCostIncludingDownPayment,
                'residualValue' => $snapshot->residualValue,
                'currency' => $snapshot->currency,
                'durationMonths' => $snapshot->durationMonths,
                'annualInterestRateBasisPoints' => $snapshot->annualInterestRateBasisPoints,
                'calculationVersion' => $snapshot->calculationVersion,
            ],
            'createdAt' => $offer->createdAt()->format(DATE_ATOM),
            'expiresAt' => $offer->expiresAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Field "%s" must be a non-empty string.', $field));
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requiredInteger(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;

        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an integer.', $field));
        }

        return $value;
    }

    private function requireBearerToken(Request $request): string
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);

        if (!$principal instanceof AuthenticatedPrincipal) {
            throw new LogicException('A verified authenticated principal is required.');
        }

        $authorization = $request->headers->get('Authorization', '');
        if (!is_string($authorization) || preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            throw new LogicException('A bearer access token is required for downstream token exchange.');
        }

        return trim($matches[1]);
    }
}
