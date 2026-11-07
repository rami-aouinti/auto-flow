<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Mail;

use AutoFlow\Notification\Domain\Notification\OfferPublishedNotification;

final class OfferPublishedEmailTemplate
{
    /** @return array{subject: string, html: string, text: string} */
    public function render(OfferPublishedNotification $notification): array
    {
        $offerId = htmlspecialchars($notification->offerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $vehicleId = htmlspecialchars($notification->vehicleId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $monthly = htmlspecialchars((string) $notification->pricing['monthlyAmount'], ENT_QUOTES, 'UTF-8');
        $currency = htmlspecialchars((string) $notification->pricing['currency'], ENT_QUOTES, 'UTF-8');
        $expires = htmlspecialchars($notification->expiresAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;background:#f3f5f8;font-family:Arial,sans-serif;color:#172033">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px"><tr><td align="center">
<table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden">
<tr><td style="background:#142b45;padding:28px 36px;color:#fff"><div style="font-size:13px;letter-spacing:2px">AUTOFLOW MOBILITY</div><h1 style="margin:12px 0 0;font-size:25px">Your offer is ready</h1></td></tr>
<tr><td style="padding:32px 36px"><p style="font-size:16px;line-height:1.6">Your leasing offer has been published. A PDF copy is attached to this email.</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f7fa;border-radius:12px"><tr><td style="padding:20px">
<div style="font-size:12px;color:#667085">OFFER REFERENCE</div><div style="font-size:17px;font-weight:bold;margin:5px 0 16px">{$offerId}</div>
<div style="font-size:12px;color:#667085">VEHICLE REFERENCE</div><div style="font-size:15px;margin:5px 0 16px">{$vehicleId}</div>
<div style="font-size:12px;color:#667085">MONTHLY INSTALMENT</div><div style="font-size:24px;font-weight:bold;color:#17634d;margin-top:5px">{$monthly} {$currency}</div>
</td></tr></table>
<p style="font-size:14px;line-height:1.6;color:#475467;margin-top:24px">This offer is valid until {$expires}. Please review the attached document for the full commercial terms.</p>
<p style="font-size:13px;color:#667085;margin-top:28px">This message was generated automatically by AutoFlow. Replies to this address are not monitored.</p></td></tr>
<tr><td style="background:#f8fafc;padding:18px 36px;font-size:11px;color:#98a2b3">AutoFlow Mobility Platform · This document is not a signed contract.</td></tr>
</table></td></tr></table>
</body></html>
HTML;
        $text = "Your AutoFlow offer {$notification->offerId} has been published. Vehicle: {$notification->vehicleId}. Monthly instalment: {$notification->pricing['monthlyAmount']} {$notification->pricing['currency']}. Valid until: {$notification->expiresAt}. The PDF is attached.";

        return ['subject' => 'Your AutoFlow leasing offer is ready', 'html' => $html, 'text' => $text];
    }
}
