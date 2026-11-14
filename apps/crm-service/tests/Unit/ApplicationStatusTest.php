<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use App\Domain\ApplicationLifecycle;
use PHPUnit\Framework\TestCase;
final class ApplicationStatusTest extends TestCase
{
    public function testHappyPathTransitionsAreAllowed(): void
    {
        self::assertTrue(ApplicationLifecycle::canTransition('submitted', 'under_review'));
        self::assertTrue(ApplicationLifecycle::canTransition('under_review', 'eligible'));
        self::assertTrue(ApplicationLifecycle::canTransition('eligible', 'approved'));
        self::assertTrue(ApplicationLifecycle::canTransition('approved', 'converted'));
    }

    public function testTerminalAndInvalidTransitionsAreRejected(): void
    {
        self::assertFalse(ApplicationLifecycle::canTransition('submitted', 'converted'));
        self::assertFalse(ApplicationLifecycle::canTransition('declined', 'under_review'));
        self::assertFalse(ApplicationLifecycle::canTransition('converted', 'withdrawn'));
        self::assertFalse(ApplicationLifecycle::canTransition('unknown', 'approved'));
    }

    public function testStatusCatalogueIsExplicit(): void
    {
        self::assertSame([
            'submitted', 'under_review', 'information_required', 'eligible',
            'approved', 'declined', 'converted', 'withdrawn',
        ], array_values(array_intersect(
            ['submitted', 'under_review', 'information_required', 'eligible', 'approved', 'declined', 'converted', 'withdrawn'],
            ApplicationLifecycle::statuses(),
        )));
    }
}
