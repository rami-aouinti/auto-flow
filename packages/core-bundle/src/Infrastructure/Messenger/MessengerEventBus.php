<?php

declare(strict_types=1);

namespace AutoFlow\\CoreBundle\\Infrastructure\\Messenger;

use AutoFlow\\CoreBundle\\Application\\Messaging\\EventBusInterface;
use AutoFlow\\CoreBundle\\Messaging\\IntegrationEvent;
use Symfony\\Component\\Messenger\\MessageBusInterface;

final readonly class MessengerEventBus implements EventBusInterface
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function publish(IntegrationEvent $event): void
    {
        $this->bus->dispatch($event);
    }
}
