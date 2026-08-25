<?php

declare(strict_types=1);

namespace App\Ai\Workflows;

use App\Ai\Agents\CrmAgent;
use App\Models\Activity;
use App\Models\Deal;
use Clutch\Laravel\Workflows\Workflow;

/**
 * The end-of-quarter chase.
 *
 * Nobody needs a model to decide the shape of this job: find what has gone
 * quiet, write to the people who own it, log what happened. The judgement is
 * in the middle, in what to actually say, and in whether it should go out at
 * all. So the control flow is ordinary PHP and the agent is called twice.
 *
 * Every irreversible thing here sits inside a step, which is the only reason
 * this is safe to resume. Move the send outside one and the second resume
 * emails everybody twice.
 */
class QuarterlyReview extends Workflow
{
    protected static ?string $agent = CrmAgent::class;

    public function produces(): array
    {
        return ['reports/*.md'];
    }

    public function handle(array $payload): mixed
    {
        $days = (int) ($payload['stale_after_days'] ?? 21);

        // Two independent reads, so they go together. Each is persisted as it
        // lands: if the second fails, a resume re-runs only the second.
        $facts = $this->steps([
            'stale' => fn (): array => $this->staleDeals($days),
            'pipeline' => fn (): array => $this->pipelineTotals(),
        ]);

        $this->emit('gathered', [
            'stale' => count($facts['stale']),
            'open_value' => $facts['pipeline']['open_cents'],
        ]);

        if ($facts['stale'] === []) {
            return ['outcome' => 'nothing to chase', 'pipeline' => $facts['pipeline']];
        }

        // The brief the agent works from is staged, so what it was asked is
        // recoverable later rather than living only in a prompt string.
        $this->stage(['brief.md' => $this->brief($facts)]);

        $summary = $this->step('summarise', fn (): string => $this->prompt(
            "Here is a pipeline brief. In under 120 words, say which deals are "
            ."most at risk and why.\n\n".$this->workspace()->get('brief.md')
        )->text);

        $drafts = $this->step('draft', fn (): array => $this->draftEmails($facts['stale']));

        $this->workspace()->put(
            'reports/review.md',
            $this->report($facts, $summary, $drafts),
        );

        // Everything above is reversible, so none of it needed asking. This is
        // the first thing that reaches a customer, so it is the first thing
        // that stops.
        $decision = $this->pause('send-outreach', [
            'summary' => $summary,
            'drafts' => $drafts,
            'recipients' => count($drafts),
        ], 'These emails go to real customers.');

        if (! ($decision['approved'] ?? false)) {
            $this->emit('declined', ['reason' => $decision['reason'] ?? null]);

            return [
                'outcome' => 'declined',
                'reason' => $decision['reason'] ?? null,
                'summary' => $summary,
            ];
        }

        // The irreversible half. Inside a step, so a worker that dies during
        // the resume does not send a second copy of anything.
        $sent = $this->step('send', fn (): array => $this->send($drafts));

        return [
            'outcome' => 'sent',
            'summary' => $summary,
            'sent' => $sent,
            'pipeline' => $facts['pipeline'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function staleDeals(int $days): array
    {
        return Deal::query()
            ->with(['company', 'contact'])
            ->whereNotIn('stage', ['won', 'lost'])
            ->where(fn ($q) => $q
                ->where('last_touched_at', '<', now()->subDays($days))
                ->orWhereNull('last_touched_at'))
            ->orderByDesc('value_cents')
            ->get()
            // Only what survives a JSON round trip: a step result is stored,
            // so returning models here would break the resume.
            ->map(fn (Deal $deal): array => [
                'reference' => $deal->reference,
                'name' => $deal->name,
                'company' => $deal->company?->name,
                'contact' => $deal->contact?->name,
                'email' => $deal->contact?->email,
                'value_cents' => $deal->value_cents,
                'stage' => $deal->stage,
                'days_quiet' => $deal->last_touched_at?->diffInDays(now()) ?? null,
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    protected function pipelineTotals(): array
    {
        return [
            'open_cents' => (int) Deal::query()->whereNotIn('stage', ['won', 'lost'])->sum('value_cents'),
            'open_count' => Deal::query()->whereNotIn('stage', ['won', 'lost'])->count(),
            'won_cents' => (int) Deal::query()->where('stage', 'won')->sum('value_cents'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stale
     * @return array<int, array<string, mixed>>
     */
    protected function draftEmails(array $stale): array
    {
        $drafts = [];

        foreach ($stale as $deal) {
            if (($deal['email'] ?? null) === null) {
                continue;
            }

            $body = $this->prompt(sprintf(
                'Write a short, plain follow-up email to %s about %s (%s), which has been quiet for %s days. '
                .'Two sentences. No subject line, no signature, no placeholders.',
                $deal['contact'],
                $deal['name'],
                $deal['reference'],
                $deal['days_quiet'] ?? 'some',
            ))->text;

            $drafts[] = [
                'reference' => $deal['reference'],
                'to' => $deal['email'],
                'contact' => $deal['contact'],
                'subject' => 'Following up on '.$deal['name'],
                'body' => trim($body),
            ];
        }

        return $drafts;
    }

    /**
     * @param  array<int, array<string, mixed>>  $drafts
     * @return array<int, string>
     */
    protected function send(array $drafts): array
    {
        $sent = [];

        foreach ($drafts as $draft) {
            $deal = Deal::query()->where('reference', $draft['reference'])->first();

            Activity::query()->create([
                'deal_id' => $deal?->id,
                'contact_id' => $deal?->contact_id,
                'kind' => 'email',
                'summary' => 'Quarterly review: '.$draft['subject'],
                'body' => $draft['body'],
                'by_agent' => true,
            ]);

            $deal?->forceFill(['last_touched_at' => now()])->save();

            $sent[] = $draft['reference'];
        }

        return $sent;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    protected function brief(array $facts): string
    {
        $lines = ['# Pipeline brief', ''];

        foreach ($facts['stale'] as $deal) {
            $lines[] = sprintf(
                '- %s · %s · %s · $%s · quiet %s days',
                $deal['reference'],
                $deal['company'],
                $deal['stage'],
                number_format($deal['value_cents'] / 100),
                $deal['days_quiet'] ?? '?',
            );
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<int, array<string, mixed>>  $drafts
     */
    protected function report(array $facts, string $summary, array $drafts): string
    {
        return implode(PHP_EOL, [
            '# Quarterly review',
            '',
            '## What the agent saw',
            '',
            $summary,
            '',
            '## Deals that have gone quiet',
            '',
            $this->brief($facts),
            '',
            '## Drafted',
            '',
            ...array_map(
                fn (array $d): string => sprintf('- %s to %s', $d['reference'], $d['to']),
                $drafts,
            ),
        ]);
    }
}
