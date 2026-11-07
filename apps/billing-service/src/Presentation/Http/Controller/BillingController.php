<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/billing')]
final readonly class BillingController
{
    public function __construct(private Connection $db)
    {
    }
    #[Route('/invoices', methods:['GET'])]
    public function invoices(Request $r): JsonResponse
    {
        return $this->run($r, function (Connection $db, string $t) use ($r): JsonResponse {
            $l = max(1, min(100, $r->query->getInt('limit', 20)));
            $o = max(0, $r->query->getInt('offset', 0));
            $items = $db->fetchAllAssociative('SELECT invoice_id,invoice_number,order_id,payment_id,currency,gross_minor,net_minor,tax_minor,tax_rate_basis_points,status,issued_at,paid_at FROM billing_invoices WHERE tenant_id=:t ORDER BY issued_at DESC LIMIT :l OFFSET :o', ['t' => $t,'l' => $l,'o' => $o]);
            $n = (int)$db->fetchOne('SELECT COUNT(*) FROM billing_invoices WHERE tenant_id=:t', ['t' => $t]);
            return new JsonResponse(['items' => $items,'total' => $n,'limit' => $l,'offset' => $o], 200, ['Cache-Control' => 'no-store']);
        });
    }
    #[Route('/invoices/{id}', methods:['GET'], requirements:['id' => '[0-9a-fA-F-]{36}'])]
    public function invoice(Request $r, string $id): JsonResponse
    {
        return $this->run($r, function (Connection $db, string $t) use ($id): JsonResponse {
            $x = $db->fetchAssociative('SELECT invoice_id,invoice_number,order_id,payment_id,currency,gross_minor,net_minor,tax_minor,tax_rate_basis_points,status,issued_at,due_at,paid_at,billing_snapshot FROM billing_invoices WHERE tenant_id=:t AND invoice_id=:id', ['t' => $t,'id' => $id]);
            if ($x === false) {
                return $this->problem(404, 'Invoice not found.');
            }$x['billing_snapshot'] = is_string($x['billing_snapshot']) ? json_decode($x['billing_snapshot'], true, 512, JSON_THROW_ON_ERROR) : $x['billing_snapshot'];
            return new JsonResponse($x, 200, ['Cache-Control' => 'no-store']);
        });
    }
    #[Route('/credit-notes', methods:['GET'])]
    public function creditNotes(Request $r): JsonResponse
    {
        return $this->run($r, function (Connection $db, string $t) use ($r): JsonResponse {
            $l = max(1, min(100, $r->query->getInt('limit', 20)));
            $o = max(0, $r->query->getInt('offset', 0));
            $items = $db->fetchAllAssociative('SELECT credit_note_id,credit_note_number,invoice_id,payment_id,order_id,currency,gross_minor,net_minor,tax_minor,reason,issued_at FROM billing_credit_notes WHERE tenant_id=:t ORDER BY issued_at DESC LIMIT :l OFFSET :o', ['t' => $t,'l' => $l,'o' => $o]);
            $n = (int)$db->fetchOne('SELECT COUNT(*) FROM billing_credit_notes WHERE tenant_id=:t', ['t' => $t]);
            return new JsonResponse(['items' => $items,'total' => $n,'limit' => $l,'offset' => $o], 200, ['Cache-Control' => 'no-store']);
        });
    }
    #[Route('/credit-notes/{id}', methods:['GET'], requirements:['id' => '[0-9a-fA-F-]{36}'])]
    public function creditNote(Request $r, string $id): JsonResponse
    {
        return $this->run($r, function (Connection $db, string $t) use ($id): JsonResponse {
            $x = $db->fetchAssociative('SELECT credit_note_id,credit_note_number,invoice_id,payment_id,order_id,currency,gross_minor,net_minor,tax_minor,tax_rate_basis_points,reason,issued_at,source_event_id FROM billing_credit_notes WHERE tenant_id=:t AND credit_note_id=:id', ['t' => $t,'id' => $id]);
            return $x === false ? $this->problem(404, 'Credit note not found.') : new JsonResponse($x, 200, ['Cache-Control' => 'no-store']);
        });
    }
    #[Route('/summary', methods:['GET'])]
    public function summary(Request $r): JsonResponse
    {
        return $this->run($r, function (Connection $db, string $t): JsonResponse {
            $i = $db->fetchAssociative("SELECT COUNT(*)::int invoice_count,COALESCE(SUM(gross_minor),0)::bigint invoiced_gross_minor,COALESCE(SUM(tax_minor),0)::bigint invoiced_tax_minor FROM billing_invoices WHERE tenant_id=:t AND status='issued'", ['t' => $t]);
            $c = $db->fetchAssociative('SELECT COUNT(*)::int credit_note_count,COALESCE(SUM(gross_minor),0)::bigint credited_gross_minor FROM billing_credit_notes WHERE tenant_id=:t', ['t' => $t]);
            return new JsonResponse(['invoices' => $i,'creditNotes' => $c], 200, ['Cache-Control' => 'no-store']);
        });
    }
    private function run(Request $r, callable $fn): JsonResponse
    {
        try {
            $p = $r->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$p instanceof AuthenticatedPrincipal || !$p->hasScope('billing:read') || $p->tenantId === null) {
                throw new AccessDeniedHttpException();
            }return $this->db->transactional(function (Connection $db) use ($p, $fn): JsonResponse {
                $db->executeQuery("SELECT set_config('app.tenant_id',:t,true)", ['t' => $p->tenantId]);
                return $fn($db, $p->tenantId);
            });
        } catch (AccessDeniedHttpException) {
            return $this->problem(403, 'Billing permission or tenant context is missing.');
        } catch (Throwable) {
            return $this->problem(503, 'Billing operation is temporarily unavailable.');
        }
    }
    private function problem(int $s, string $d): JsonResponse
    {
        return new JsonResponse(['type' => 'about:blank','title' => match($s) {
            403 => 'Forbidden',404 => 'Not Found',default => 'Service Unavailable'
        },'status' => $s,'detail' => $d], $s, ['Content-Type' => 'application/problem+json','Cache-Control' => 'no-store']);
    }
}
