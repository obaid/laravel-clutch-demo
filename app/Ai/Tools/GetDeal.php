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

class GetDeal implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Read one deal in full, including its recent activity timeline.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'reference' => $schema->string()->description('The deal reference, such as IN-401.')->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        $deal = Deal::query()
            ->with(['company', 'contact'])
            ->where('reference', (string) $request['reference'])
            ->first();

        if (! $deal) {
            return "No deal with reference {$request['reference']}.";
        }

        $lines = [
            "reference:  {$deal->reference}",
            "name:       {$deal->name}",
            "company:    {$deal->company->name} ({$deal->company->domain})",
            'contact:    '.($deal->contact ? "{$deal->contact->name} <{$deal->contact->email}>, {$deal->contact->title}" : 'none'),
            "value:      {$deal->value()}",
            'discount:   '.($deal->discount_percent ? "{$deal->discount_percent}% (net {$deal->netValue()})" : 'none'),
            "stage:      {$deal->stage}",
            "owner:      {$deal->owner}",
            'last touch: '.($deal->last_touched_at?->diffForHumans() ?? 'never'),
        ];

        $timeline = $deal->activities()->limit(8)->get()
            ->map(fn (Activity $a): string => '  '.$a->created_at->format('Y-m-d')." [{$a->kind}] {$a->summary}")
            ->implode("\n");

        return implode("\n", $lines)."\n\nactivity:\n".($timeline ?: '  none');
    }
}
