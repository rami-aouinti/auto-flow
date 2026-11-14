<?php
declare(strict_types=1);
namespace App\Application\Port\Out;
interface ChatProviderInterface
{
    /** @param list<array{role: string, content: string}> $messages
     *  @return array{content: string, model: string, input_tokens: int|null, output_tokens: int|null}
     */
    public function chat(array $messages): array;
}
