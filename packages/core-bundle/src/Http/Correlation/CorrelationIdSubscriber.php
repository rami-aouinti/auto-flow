<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Http\Correlation;

use AutoFlow\CoreBundle\Context\CorrelationContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class CorrelationIdSubscriber implements EventSubscriberInterface
{
    private const ATTRIBUTE_CORRELATION_ID = '_autoflow_correlation_id';
    private const ATTRIBUTE_PREVIOUS_CORRELATION_ID = '_autoflow_previous_correlation_id';

    public function __construct(
        private CorrelationContext $context,
        private string $headerName,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRequest', 1000],
            KernelEvents::RESPONSE => ['onResponse', -1000],
            KernelEvents::FINISH_REQUEST => ['onFinishRequest', -1000],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $incoming = trim((string) $request->headers->get($this->headerName, ''));

        $correlationId = preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $incoming) === 1
            ? $incoming
            : bin2hex(random_bytes(16));

        $request->attributes->set(self::ATTRIBUTE_PREVIOUS_CORRELATION_ID, $this->context->get());
        $request->attributes->set(self::ATTRIBUTE_CORRELATION_ID, $correlationId);
        $this->context->set($correlationId);
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get(self::ATTRIBUTE_CORRELATION_ID);

        if (is_string($correlationId) && $correlationId !== '') {
            $event->getResponse()->headers->set($this->headerName, $correlationId);
        }
    }

    public function onFinishRequest(FinishRequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $previous = $event->getRequest()->attributes->get(self::ATTRIBUTE_PREVIOUS_CORRELATION_ID);
        $this->context->set(is_string($previous) ? $previous : null);
    }
}
