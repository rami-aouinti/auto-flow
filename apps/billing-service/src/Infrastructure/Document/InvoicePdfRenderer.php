<?php

declare(strict_types=1);

namespace App\Infrastructure\Document;

use Dompdf\Dompdf;
use Dompdf\Options;
use DomainException;

final class InvoicePdfRenderer
{
    /** @param array<string,mixed> $invoice */
    public function render(array $invoice): string
    {
        $snapshot = $invoice['billing_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($snapshot) || array_is_list($snapshot)
            || ($invoice['status'] ?? null) !== 'issued'
            || ($snapshot['legalReadiness']['readyToIssue'] ?? false) !== true) {
            throw new DomainException('An invoice PDF can only be generated from a legally complete issued invoice.');
        }

        $issuer = is_array($snapshot['issuer'] ?? null) ? $snapshot['issuer'] : [];
        $recipient = is_array($snapshot['recipient'] ?? null) ? $snapshot['recipient'] : [];
        $issuerAddress = is_array($issuer['address'] ?? null) ? $issuer['address'] : [];
        $recipientAddress = is_array($recipient['address'] ?? null) ? $recipient['address'] : [];
        $gross = (int) ($invoice['gross_minor'] ?? 0);
        $net = (int) ($invoice['net_minor'] ?? 0);
        $tax = (int) ($invoice['tax_minor'] ?? 0);
        $currency = strtoupper((string) ($invoice['currency'] ?? 'EUR'));
        $money = static fn (int $minor): string => number_format($minor / 100, 2, ',', '.') . ' ' . $currency;
        $address = static function (array $value): string {
            return implode('<br>', array_filter(array_map(
                static fn (mixed $part): string => htmlspecialchars((string) ($part ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                [$value['line1'] ?? '', $value['line2'] ?? '', trim((string) ($value['postalCode'] ?? '') . ' ' . (string) ($value['city'] ?? '')), $value['countryCode'] ?? ''],
            ), static fn (string $part): bool => $part !== ''));
        };
        $issuerName = htmlspecialchars((string) ($issuer['legalName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $recipientName = htmlspecialchars((string) ($recipient['displayName'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $invoiceNumber = htmlspecialchars((string) ($invoice['invoice_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $taxRate = number_format(((int) ($invoice['tax_rate_basis_points'] ?? 0)) / 100, 2, ',', '');

        $html = '<!doctype html><html lang="de"><meta charset="utf-8"><style>
            body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:11px;line-height:1.5}
            h1{font-size:26px;margin:0 0 8px}h2{font-size:13px;margin:0 0 8px}
            .muted{color:#667085}.top{display:flex;justify-content:space-between;margin-bottom:45px}
            .cols{display:flex;justify-content:space-between;margin-bottom:38px}.col{width:46%}
            table{width:100%;border-collapse:collapse;margin-top:25px}th,td{padding:10px;border-bottom:1px solid #d0d5dd;text-align:right}
            th:first-child,td:first-child{text-align:left}.total{font-weight:bold;font-size:14px}
            .footer{margin-top:55px;border-top:1px solid #d0d5dd;padding-top:12px;font-size:9px;color:#667085}
        </style><body>
        <div class="top"><div><div class="muted">RECHNUNG</div><h1>' . $invoiceNumber . '</h1><div class="muted">Ausgestellt am ' . htmlspecialchars((string) ($invoice['issued_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div><div><strong>' . $issuerName . '</strong><br>' . $address($issuerAddress) . '</div></div>
        <div class="cols"><div class="col"><h2>Rechnungsempfänger</h2><strong>' . $recipientName . '</strong><br>' . $address($recipientAddress) . '<br>' . htmlspecialchars((string) ($recipient['vatId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div><div class="col"><h2>Rechnungsdaten</h2>Bestellnummer: ' . htmlspecialchars((string) ($invoice['order_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>Zahlungsreferenz: ' . htmlspecialchars((string) ($invoice['payment_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div></div>
        <table><thead><tr><th>Beschreibung</th><th>Netto</th><th>USt. (' . $taxRate . '%)</th><th>Brutto</th></tr></thead><tbody><tr><td>Fahrzeugvertrag – AutoFlow</td><td>' . $money($net) . '</td><td>' . $money($tax) . '</td><td>' . $money($gross) . '</td></tr><tr class="total"><td colspan="3">Gesamtbetrag</td><td>' . $money($gross) . '</td></tr></tbody></table>
        <div class="footer">Steuer-ID: ' . htmlspecialchars((string) ($issuer['taxNumber'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' · USt-IdNr.: ' . htmlspecialchars((string) ($issuer['vatId'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '<br>Dieses Dokument basiert auf dem beim Zahlungseingang gespeicherten unveränderlichen Rechnungs- und Kundensnapshot.</div>
        </body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
