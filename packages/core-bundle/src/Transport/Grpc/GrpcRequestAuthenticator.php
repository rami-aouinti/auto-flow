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
        if ($authorization === null || preg_match('/^Bearer\\s+([^\\s]+)$/iD', $authorization, $matches) !== 1) {
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
     * RoadRunner stores incoming request headers under the "metadata" context
     * value. Read that map first; retain direct lookup as a compatibility fallback.
     *
     * @param non-empty-string $key
     */
    private function metadataValue(ContextInterface $context, string $key): ?string
    {
        $metadata = $context->getValue('metadata');
        if (is_array($metadata)) {
            foreach ($metadata as $metadataKey => $value) {
                if (is_string($metadataKey) && strcasecmp($metadataKey, $key) === 0) {
                    return $this->singleMetadataValue($value);
                }
            }
        }

        return $this->singleMetadataValue($context->getValue($key));
    }

    private function singleMetadataValue(mixed $value): ?string
    {
        if (is_array($value)) {
            if (count($value) !== 1) {
                return null;
            }

            $value = reset($value);
        }

        return is_string($value) ? $value : null;
    }
}
