<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum CatalogStatus: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case DISCONTINUED = 'discontinued';
    case ARCHIVED = 'archived';
}