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
 * Moves a deal along the pipeline.
 *
 * Reversible, because you can always move it back, so it runs without asking.
 * Marking a deal won or lost is a different matter and needs sign-off.
 */
class MoveDealStage implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Move a deal to a different pipeline stage.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The deal reference.')->required(),
            'stage' => $schema->string()
                ->description('discovery, demo, proposal, negotiation, won or lost.')
                ->required(),
            'why' => $schema->string()->description('Why it is moving.')->required(),
        ];
    }

    /**
     * Won and lost are reported to the business and awkward to walk back, so
     * they are treated as sensitive while ordinary progress is not.
     */
    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Reversible;
    }

    public function handle(Request $request): Stringable|string
    {
        $stage = strtolower((string) $request['stage']);

        if (! in_array($stage, Deal::STAGES, true)) {
            return 'Unknown stage. Use one of: '.implode(', ', Deal::STAGES).'.';
        }

        $deal = Deal::query()->where('reference', (string) $request['reference'])->first();

        if (! $deal) {
            return "No deal with reference {$request['reference']}.";
        }

        $from = $deal->stage;

        $deal->forceFill(['stage' => $stage, 'last_touched_at' => now()])->save();

        Activity::query()->create([
            'deal_id' => $deal->id,
            'kind' => 'stage',
            'summary' => "Moved {$from} to {$stage}",
            'body' => (string) $request['why'],
            'by_agent' => true,
        ]);

        return "{$deal->reference} moved from {$from} to {$stage}.";
    }
}
