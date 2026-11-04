<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

enum OfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
}
