<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

use InvalidArgumentException;

enum CatalogStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case DISCONTINUED = 'discontinued';
    case ARCHIVED = 'archived';

    public function transitionTo(self $next): self
    {
        if ($next === $this) {
            return $this;
        }

        $allowed = match ($this) {
            self::DRAFT => [self::PUBLISHED, self::ARCHIVED],
            self::PUBLISHED => [self::DISCONTINUED, self::ARCHIVED],
            self::DISCONTINUED => [self::PUBLISHED, self::ARCHIVED],
            self::ARCHIVED => [],
        };

        if (!in_array($next, $allowed, true)) {
            throw new InvalidArgumentException(
                sprintf('Catalogue status cannot transition from %s to %s.', $this->value, $next->value),
            );
        }

        return $next;
    }
}
