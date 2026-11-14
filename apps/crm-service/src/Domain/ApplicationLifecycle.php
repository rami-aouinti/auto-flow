<?php
declare(strict_types=1);
namespace App\Domain;
final class ApplicationLifecycle
{
    private const TRANSITIONS = [
        'submitted' => ['under_review', 'withdrawn'],
        'under_review' => ['information_required', 'eligible', 'declined', 'withdrawn'],
        'information_required' => ['under_review', 'withdrawn'],
        'eligible' => ['approved', 'declined', 'withdrawn'],
        'approved' => ['converted'],
        'declined' => [],
        'converted' => [],
        'withdrawn' => [],
    ];
    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }
}
