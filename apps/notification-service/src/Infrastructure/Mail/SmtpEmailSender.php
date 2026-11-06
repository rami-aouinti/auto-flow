<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Mail;

use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;
use AutoFlow\Notification\Infrastructure\Pdf\OfferPdfGenerator;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final readonly class SmtpEmailSender
{
    public function __construct(
        private string $dsn,
        private string $fromAddress,
        private string $fromName,
        private OfferPublishedEmailTemplate $template,
        private OfferPdfGenerator $pdfGenerator,
    ) {
    }

    public function sendOfferPublished(string $recipient, OfferPublishedNotification $notification): void
    {
        $content = $this->template->render($notification);
        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($recipient)
            ->subject($content['subject'])
            ->html($content['html'])
            ->text($content['text'])
            ->attach(
                $this->pdfGenerator->generate($notification),
                sprintf('autoflow-offer-%s.pdf', $notification->offerId),
                'application/pdf',
            );

        (new Mailer(Transport::fromDsn($this->dsn)))->send($email);
    }
}