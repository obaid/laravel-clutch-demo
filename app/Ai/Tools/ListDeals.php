<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Deal;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListDeals implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'List deals in the pipeline, optionally filtered by stage or owner.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'stage' => $schema->string()->description('discovery, demo, proposal, negotiation, won or lost.'),
            'owner' => $schema->string()->description('Filter to one owner.'),
            'stale_only' => $schema->boolean()->description('Only deals untouched for over 14 days.'),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        $deals = Deal::query()
            ->with('company')
            ->when($request['stage'] ?? null, fn ($q, $s) => $q->where('stage', $s))
            ->when($request['owner'] ?? null, fn ($q, $o) => $q->where('owner', $o))
            ->orderBy('last_touched_at')
            ->get()
            ->when((bool) ($request['stale_only'] ?? false), fn ($c) => $c->filter->isStale());

        if ($deals->isEmpty()) {
            return 'No deals match that.';
        }

        return $deals->map(fn (Deal $d): string => sprintf(
            '%-7s %-26s %-10s %-12s %s',
            $d->reference,
            \Illuminate\Support\Str::limit($d->name, 25),
            $d->value(),
            $d->stage,
            'last touched '.($d->last_touched_at?->diffForHumans() ?? 'never'),
        ))->implode("\n");
    }
}
