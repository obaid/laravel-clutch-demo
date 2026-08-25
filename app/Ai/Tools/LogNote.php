<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\Deal;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Writes a note onto a deal's timeline. Reversible, so it never asks.
 */
class LogNote implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Add a note to a deal\'s activity timeline.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The deal reference.')->required(),
            'summary' => $schema->string()->description('One line.')->required(),
            'body' => $schema->string()->description('The detail.'),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Reversible;
    }

    public function handle(Request $request): Stringable|string
    {
        $deal = Deal::query()->where('reference', (string) $request['reference'])->first();

        if (! $deal) {
            return "No deal with reference {$request['reference']}.";
        }

        Activity::query()->create([
            'deal_id' => $deal->id,
            'contact_id' => $deal->contact_id,
            'kind' => 'note',
            'summary' => (string) $request['summary'],
            'body' => (string) ($request['body'] ?? ''),
            'by_agent' => true,
        ]);

        $deal->forceFill(['last_touched_at' => now()])->save();

        return "Noted on {$deal->reference}.";
    }
}
