<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/realtime')]
final readonly class MercureAccessController
{
    #[Route('/access', methods: ['GET'])]
    public function access(Request $request): JsonResponse
    {
        $principal = $request->attributes->get(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
        if (!$principal instanceof AuthenticatedPrincipal || !$principal->tenantId
            || (!$principal->hasScope('customers:read') && !$principal->hasScope('customers:portal'))) {
            throw new AccessDeniedHttpException('A tenant-scoped customer permission is required.');
        }

        $key = getenv('MERCURE_SUBSCRIBER_JWT_KEY') ?: '';
        if (strlen($key) < 32) {
            return new JsonResponse(['detail' => 'Mercure subscriber signing is not configured.'], 503, ['Cache-Control' => 'no-store']);
        }
        $topicBase = rtrim(getenv('AUTOFLOW_MERCURE_TOPIC_BASE') ?: 'https://autoflow.local/tenants', '/');
        $topic = $topicBase . '/' . rawurlencode($principal->tenantId) . '/events';
        $now = time();
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iat' => $now,
            'exp' => $now + 300,
            'mercure' => ['subscribe' => [$topic]],
        ], JSON_THROW_ON_ERROR));
        $signature = $encode(hash_hmac('sha256', $header . '.' . $payload, $key, true));
        $token = $header . '.' . $payload . '.' . $signature;

        $response = new JsonResponse(['topic' => $topic, 'expiresAt' => $now + 300], 200, ['Cache-Control' => 'no-store']);
        $response->headers->setCookie(Cookie::create('mercureAuthorization')
            ->withValue($token)
            ->withExpires(new \DateTimeImmutable('+5 minutes'))
            ->withPath('/')
            ->withSecure($request->isSecure() || filter_var(getenv('MERCURE_COOKIE_SECURE') ?: 'false', FILTER_VALIDATE_BOOL))
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX));

        return $response;
    }
}
