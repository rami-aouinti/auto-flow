<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Application\Messaging;

use AutoFlow\CoreBundle\Messaging\IntegrationEvent;

/**
 * Port for publishing integration events.
 *
 * The implementation only transports the event. Transactional Outbox
 * persistence and delivery guarantees remain owned by each bounded context.
 */
interface EventBusInterface
{
    public function publish(IntegrationEvent $event): void;
}
