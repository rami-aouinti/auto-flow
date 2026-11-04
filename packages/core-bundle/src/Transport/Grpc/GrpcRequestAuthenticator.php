<?php

declare(strict_types=1);

namespace AutoFlow\CoreBundle\Transport\Grpc;

use AutoFlow\CoreBundle\Security\AuthenticatedPrincipal;
use AutoFlow\CoreBundle\Security\InvalidTokenException;
use AutoFlow\CoreBundle\Security\JwksUnavailableException;
use AutoFlow\CoreBundle\Security\JwtTokenVerifier;
use AutoFlow\CoreBundle\Security\MissingScopeException;
use AutoFlow\CoreBundle\Security\TenantDelegationException;
use AutoFlow\CoreBundle\Security\TenantPrincipalResolver;
use Spiral\RoadRunner\GRPC\ContextInterface;

final readonly class GrpcRequestAuthenticator
{
    public function __construct(
        private JwtTokenVerifier $tokenVerifier,
        private TenantPrincipalResolver $tenantPrincipalResolver,
    ) {
    }

    /**
     * @throws InvalidTokenException
     * @throws JwksUnavailableException
     * @throws MissingScopeException
     * @throws TenantDelegationException
     */
    public function authenticate(
        ContextInterface $context,
        string $requiredScope,
        bool $tenantRequired = true,
    ): AuthenticatedPrincipal {
        $authorization = $this->metadataValue($context, 'authorization');

        if ($authorization === null || preg_match('/^Bearer\s+([^\s]+)$/iD', trim($authorization), $matches) !== 1) {
            $values = $context->getValues();
            $keys = array_map('strval', array_keys($values));
            $shapes = [];
            foreach ($values as $key => $value) {
                $shapes[(string) $key] = is_array($value)
                    ? ['type' => 'array', 'count' => count($value)]
                    : ['type' => get_debug_type($value)];
            }

            error_log(sprintf(
                '[AutoFlow gRPC auth] Authorization metadata unavailable or malformed; context_keys=%s; value_shapes=%s',
                json_encode($keys, JSON_UNESCAPED_SLASHES) ?: '[]',
                json_encode($shapes, JSON_UNESCAPED_SLASHES) ?: '{}',
            ));

            throw new InvalidTokenException('A valid bearer token is required for this gRPC method.');
        }

        $principal = $this->tokenVerifier->verify($matches[1]);
        if ($tenantRequired) {
            $principal = $this->tenantPrincipalResolver->resolve(
                $principal,
                $this->metadataValue($context, 'x-tenant-id'),
            );
        }

        if (!$principal->hasScope($requiredScope)) {
            throw new MissingScopeException('The access token does not grant the required gRPC permission.');
        }

        return $principal;
    }

    /**
     * Read RoadRunner metadata without assuming a single key spelling or
     * whether the transport represents values as strings or string lists.
     *
     * @param non-empty-string $key
     */
    private function metadataValue(ContextInterface $context, string $key): ?string
    {
        $values = $context->getValues();
        $acceptedKeys = [
            $key,
            'grpc-metadata-' . $key,
            'grpc.metadata.' . $key,
        ];

        foreach ($values as $contextKey => $value) {
            $normalizedKey = strtolower((string) $contextKey);
            if (!in_array($normalizedKey, $acceptedKeys, true)) {
                continue;
            }

            if (is_array($value)) {
                if (count($value) !== 1) {
                    return null;
                }
                $value = reset($value);
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        // Keep compatibility with ContextInterface implementations whose
        // getValues() does not expose metadata but getValue() does.
        $value = $context->getValue($key);
        if (is_array($value)) {
            if (count($value) !== 1) {
                return null;
            }
            $value = reset($value);
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
