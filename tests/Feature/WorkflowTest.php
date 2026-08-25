<?php

declare(strict_types=1);

use App\Ai\Agents\CrmAgent;
use App\Ai\Workflows\QuarterlyReview;
use App\Models\Activity;
use App\Models\Deal;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Artifact;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Runtime\RunCoordinator;
use Clutch\Laravel\Workflows\Workflow;
use Database\Seeders\CrmSeeder;

/**
 * The workflow, end to end, against the real coordinator and driver. Only the
 * model call is faked.
 *
 * Almost every assertion here is a count. That is deliberate: the claim a
 * durable workflow makes is not that it produces the right answer once, it is
 * that interrupting it does not make it do the work twice.
 */

beforeEach(function (): void {
    $this->seed(CrmSeeder::class);

    // Deterministic drafts, so the counting is about the harness and not the
    // model. Enough responses for a summary plus one email per stale deal,
    // several times over, since a resume re-enters the body.
    CrmAgent::fake(array_fill(0, 40, 'Just checking in on this one.'));

    $this->emails = fn (): int => Activity::query()
        ->where('summary', 'like', 'Quarterly review:%')
        ->count();
});

function stale(): int
{
    return Deal::query()
        ->whereNotIn('stage', ['won', 'lost'])
        ->whereNotNull('contact_id')
        ->where(fn ($q) => $q->where('last_touched_at', '<', now()->subDays(7))->orWhereNull('last_touched_at'))
        ->count();
}

// Running it ------------------------------------------------------------------

it('parks at the approval without sending anything', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval)
        ->and(($this->emails)())->toBe(0);

    // What a human sees is the drafts, not an opaque tool call.
    $approval = Approval::query()->where('run_id', $run->id)->firstOrFail();

    expect($approval->tool_name)->toBe('send-outreach')
        ->and($approval->arguments['drafts'])->not->toBeEmpty()
        ->and($approval->arguments['summary'])->toBeString();
});

it('sends exactly one email per drafted deal when approved', function (): void {
    // Counted before the run, because sending marks those deals as touched and
    // they stop being stale.
    $expected = stale();
    expect($expected)->toBeGreaterThan(0);

    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    Workflow::resume($run->id, ['approved' => true]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and(($this->emails)())->toBe($expected)
        ->and($run->structured_output['outcome'])->toBe('sent')
        ->and($run->structured_output['sent'])->toHaveCount($expected);
});

it('sends nothing when declined, and says why', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    Workflow::resume($run->id, ['approved' => false, 'reason' => 'Wrong week for it.']);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['outcome'])->toBe('declined')
        ->and($run->structured_output['reason'])->toBe('Wrong week for it.')
        ->and(($this->emails)())->toBe(0);
});

it('records the report as an artifact', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);
    Workflow::resume($run->id, ['approved' => true]);

    $artifact = Artifact::query()->where('run_id', $run->id)->firstOrFail();

    expect($artifact->name)->toBe('reports/review.md')
        ->and($artifact->contents())->toContain('# Quarterly review')
        ->and($artifact->hasIntactContents())->toBeTrue();
});

// The part that actually matters ----------------------------------------------

it('replays the finished steps instead of re-running them', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    $ranFirst = stepsIn($run, replayed: false);
    expect($ranFirst)->toContain('stale', 'pipeline', 'summarise', 'draft');

    Workflow::resume($run->id, ['approved' => true]);

    $replayed = stepsIn($run->refresh(), replayed: true);

    // The second pass re-entered the same body and skipped everything the
    // first pass had already paid for.
    expect($replayed)->toContain('stale', 'pipeline', 'summarise', 'draft')
        ->and(stepsIn($run, replayed: false))->toContain('send');
});

it('does not send a second time when a finished run is retried', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);
    Workflow::resume($run->id, ['approved' => true]);

    $sent = ($this->emails)();
    expect($sent)->toBeGreaterThan(0);

    $coordinator = app(RunCoordinator::class);
    $retried = $coordinator->retryRun($run->refresh());
    $coordinator->executeRun($retried->id);

    expect($retried->refresh()->status)->toBe(RunStatus::Completed)
        // The whole promise, in one number.
        ->and(($this->emails)())->toBe($sent)
        ->and(stepsIn($retried, replayed: false))->toBeEmpty();
});

it('survives a worker that vanished, without repeating a step', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    expect($run->refresh()->status)->toBe(RunStatus::AwaitingApproval);

    // A SIGKILL leaves a run claiming to be running with a dead heartbeat.
    $run->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
        'started_at' => now()->subHour(),
    ])->save();

    dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: true));

    // Whatever the reaper decided, no work was repeated and nothing was sent.
    expect(($this->emails)())->toBe(0);

    $checkpoint = \Clutch\Laravel\Models\Checkpoint::query()
        ->where('session_id', $run->session_id)
        ->where('driver', 'workflow')
        ->latest('id')
        ->firstOrFail();

    expect(array_keys($checkpoint->payload['steps']))
        ->toContain('stale', 'pipeline', 'summarise', 'draft');
});

it('stops cleanly when there is nothing to chase', function (): void {
    Deal::query()->update(['last_touched_at' => now()]);

    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->structured_output['outcome'])->toBe('nothing to chase')
        // It never reached the pause, because there was nothing to approve.
        ->and(Approval::query()->count())->toBe(0);
});

// The pages -------------------------------------------------------------------

it('renders the workflow pages', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    $this->get('/workflows')->assertOk()->assertSee('quarterly review', false);
    $this->get('/workflows/'.$run->id)->assertOk()->assertSee('The plan', false);
});

it('reports live state the page can draw', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    $state = $this->getJson('/workflows/'.$run->id.'/state')->assertOk()->json();

    expect($state['status'])->toBe('awaiting_approval')
        ->and($state['steps'])->toHaveCount(6)
        ->and(collect($state['steps'])->firstWhere('key', 'stale')['status'])->toBe('done')
        ->and(collect($state['steps'])->firstWhere('key', 'send')['status'])->toBe('pending')
        ->and($state['passes'])->toHaveCount(1)
        ->and($state['approval']['tool_name'])->toBe('send-outreach');
});

it('shows the second pass as skipped work', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);
    Workflow::resume($run->id, ['approved' => true]);

    $state = $this->getJson('/workflows/'.$run->id.'/state')->json();

    expect($state['passes'])->toHaveCount(2)
        ->and($state['passes'][0]['replayed'])->toBeEmpty()
        ->and($state['passes'][1]['replayed'])->toContain('stale', 'pipeline', 'summarise', 'draft')
        ->and($state['passes'][1]['ran'])->toBe(['send']);
});

it('refuses to decide a run that is not waiting', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);
    Workflow::resume($run->id, ['approved' => true]);

    $this->post('/workflows/'.$run->id.'/decide', ['approved' => true])->assertStatus(409);
});

/**
 * @return array<int, string>
 */
function stepsIn(Run $run, bool $replayed): array
{
    return $run->events()
        ->where('type', 'step.completed')
        ->get()
        ->filter(fn ($e): bool => (bool) ($e->payload['replayed'] ?? false) === $replayed)
        ->pluck('payload.step')
        ->unique()
        ->values()
        ->all();
}

it('links a reaped run to the attempt that replaced it', function (): void {
    $run = QuarterlyReview::start()->dispatch(['stale_after_days' => 7]);

    $run->forceFill([
        'status' => RunStatus::Running,
        'heartbeat_at' => now()->subHour(),
        'started_at' => now()->subHour(),
    ])->save();

    dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: true));

    $retry = Run::query()->where('retry_of_run_id', $run->id)->firstOrFail();

    // The new attempt recovered to the same pause without redoing the work.
    expect($retry->status)->toBe(RunStatus::AwaitingApproval)
        ->and(stepsIn($retry, replayed: true))->toContain('stale', 'pipeline', 'summarise', 'draft')
        ->and(stepsIn($retry, replayed: false))->toBeEmpty()
        ->and(($this->emails)())->toBe(0);

    // And the demo says so, rather than looking like it simply broke.
    $this->get('/workflows/'.$run->id)->assertOk()->assertSee('started another one', false);
});
