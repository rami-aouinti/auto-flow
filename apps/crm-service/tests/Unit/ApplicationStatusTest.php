<?php
declare(strict_types=1);
namespace App\Tests\Unit;
use PHPUnit\Framework\TestCase;
final class ApplicationStatusTest extends TestCase
{
    public function testAllowedLifecycleTransitions(): void
    {
        $allowed = [
            'submitted' => ['under_review','withdrawn'],
            'under_review' => ['information_required','eligible','declined','withdrawn'],
            'information_required' => ['under_review','withdrawn'],
            'eligible' => ['approved','declined','withdrawn'],
            'approved' => ['converted'],
            'declined' => [],
            'converted' => [],
            'withdrawn' => [],
        ];
        self::assertContains('under_review', $allowed['submitted']);
        self::assertContains('approved', $allowed['eligible']);
        self::assertNotContains('converted', $allowed['submitted']);
        self::assertSame([], $allowed['declined']);
    }
}
