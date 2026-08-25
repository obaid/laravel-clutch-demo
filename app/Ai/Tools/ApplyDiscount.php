<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\Deal;
use Clutch\Laravel\Contracts\IdempotentTool;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Discounts a deal, which is the one thing here that costs real revenue.
 *
 * Three protections meet on this class, each guarding a different failure:
 *
 *   Approvable       a human decides, and the run waits however long that takes
 *   SensitiveTool    the policy engine knows this is irreversible
 *   IdempotentTool   a retry cannot stack a second discount after a crash
 *
 * The counter on the deal is what makes the last one visible. Deliver it twice
 * from the panel and watch the body run once.
 */
class ApplyDiscount implements Approvable, IdempotentTool, SensitiveTool, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Apply a discount to a deal. This changes contracted revenue and cannot be undone.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The deal reference.')->required(),
            'percent' => $schema->integer()->description('Discount percentage, 1 to 40.')->required(),
            'justification' => $schema->string()->description('Why this is warranted.')->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    /**
     * Key the discount, not the call. Two deliveries for one deal have to
     * collide even though their tool-call IDs differ.
     */
    public function idempotencyKey(ToolInvocation $invocation): string
    {
        return 'discount:'.($invocation->arguments['reference'] ?? 'unknown');
    }

    public function handle(Request $request): Stringable|string
    {
        $deal = Deal::query()->where('reference', (string) $request['reference'])->first();

        if (! $deal) {
            return "No deal with reference {$request['reference']}.";
        }

        // Counts every execution of this body. The ledger keeps it at one.
        $deal->increment('discount_attempts');

        if ($deal->discount_percent !== null) {
            return "{$deal->reference} already carries a {$deal->discount_percent}% discount.";
        }

        $percent = max(1, min(40, (int) $request['percent']));

        $deal->forceFill([
            'discount_percent' => $percent,
            'last_touched_at' => now(),
        ])->save();

        Activity::query()->create([
            'deal_id' => $deal->id,
            'kind' => 'discount',
            'summary' => "Applied {$percent}% discount",
            'body' => (string) $request['justification'],
            'by_agent' => true,
        ]);

        return "Applied {$percent}% to {$deal->reference}. Net value is now {$deal->netValue()}.";
    }
}
