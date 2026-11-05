<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation;

use App\Presentation\Http\Dto\Request\InvalidPaginationException;
use App\Presentation\Http\Dto\Request\VehicleSearchCriteria;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VehicleSearchCriteriaTest extends TestCase
{
    public function testRejectsArrayShapedFilterValues(): void
    {
        $request = Request::create('/api/v1/vehicles?q[]=BMW');

        $this->expectException(InvalidArgumentException::class);

        VehicleSearchCriteria::fromRequest($request);
    }

    public function testRejectsArrayShapedPaginationValuesAsBadPagination(): void
    {
        $request = Request::create('/api/v1/vehicles?limit[]=20');

        $this->expectException(InvalidPaginationException::class);

        VehicleSearchCriteria::fromRequest($request);
    }

    public function testAcceptsValidFiltersAndPagination(): void
    {
        $request = Request::create('/api/v1/vehicles?q=Golf&minPowerKw=100&limit=10&offset=5');

        $criteria = VehicleSearchCriteria::fromRequest($request);

        self::assertSame('Golf', $criteria->filters['q']);
        self::assertSame(100, $criteria->filters['minPowerKw']);
        self::assertSame(10, $criteria->limit);
        self::assertSame(5, $criteria->offset);
    }
}
