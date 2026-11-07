<?php

declare(strict_types=1);

namespace AutoFlow\Notification\Infrastructure\Observability;

final class StructuredLogger
{
    /** @param array<string, scalar|null> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $message, array $context): void
    {
        error_log((string) json_encode([
            'timestamp' => gmdate(DATE_ATOM),
            'level' => $level,
            'service' => 'autoflow-notification-service',
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
