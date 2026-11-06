<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Application;

use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;
use AutoFlow\Notification\Infrastructure\Identity\KeycloakUserDirectory;
use AutoFlow\Notification\Infrastructure\Mail\SmtpEmailSender;
use AutoFlow\Notification\Infrastructure\Persistence\NotificationDeliveryRepository;

final readonly class HandleOfferPublished
{
    public function __construct(
        private KeycloakUserDirectory $users,
        private SmtpEmailSender $mailer,
        private NotificationDeliveryRepository $deliveries,
    ) {
    }

    public function __invoke(OfferPublishedNotification $notification): bool
    {
        if (!$this->deliveries->begin($notification->eventId, $notification->tenantId, $notification->offerId)) {
            return false;
        }

        $recipient = $this->users->emailForSubject($notification->createdBy);
        $this->mailer->sendOfferPublished($recipient, $notification);
        $this->deliveries->markSent($notification->eventId, $recipient);

        return true;
    }
}