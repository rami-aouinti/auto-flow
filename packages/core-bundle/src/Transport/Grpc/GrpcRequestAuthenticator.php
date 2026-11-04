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
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;

final readonly class GrpcRequestAuthenticator
{
    public function __construct(
        private JwtTokenVerifier $tokenVerifier,
        private TenantPrincipalResolver $tenantPrincipalResolver,
        private LoggerInterface $logger,
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
        // RoadRunner's Server builds Context directly from CallContext::context.
        // Therefore incoming gRPC metadata is exposed as top-level context values.
        $authorizationValue = $context->getValue('authorization');
        $authorization = $this->singleMetadataValue($authorizationValue);

        if ($authorization === null || preg_match('/^Bearer\\s+([^\\s]+)$/iD', $authorization, $matches) !== 1) {
            $this->logger->warning('gRPC authorization metadata is missing or malformed.', [
                'context_keys' => array_keys($context->getValues()),
                'authorization_type' => get_debug_type($authorizationValue),
                'authorization_value_count' => is_array($authorizationValue) ? count($authorizationValue) : null,
                'authorization_present' => $authorization !== null,
            ]);

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
     * @param non-empty-string $key
     */
    private function metadataValue(ContextInterface $context, string $key): ?string
    {
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
