<?php

declare(strict_types=1);

use App\Ai\Agents\CrmAgent;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Thread;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\ToolExecution;
use Database\Seeders\CrmSeeder;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;

/**
 * These run the real driver, coordinator, approval broker and ledger. Only the
 * call to the model is faked, through Laravel AI's own gateway.
 */

beforeEach(function (): void {
    $this->seed(CrmSeeder::class);
});

// The CRM ---------------------------------------------------------------------

it('renders every page', function (string $path): void {
    $this->get($path)->assertOk();
})->with(['/', '/deals', '/companies', '/contacts', '/activity']);

it('renders a pane fragment without the shell', function (): void {
    $full = $this->get('/deals')->getContent();
    $pane = $this->withHeader('X-Pane', 'main')->get('/deals')->getContent();

    // The shell carries the agent panel, so a pane swap must not include it.
    expect($full)->toContain('<aside')
        ->and($pane)->not->toContain('<aside')
        ->and($pane)->toContain('Deals');
});

// The agent -------------------------------------------------------------------

it('opens a thread backed by a Clutch session', function (): void {
    $this->getJson('/agent/thread')->assertOk()->assertJsonStructure(['thread_id', 'runs']);

    expect(Thread::query()->firstOrFail()->session_id)->toStartWith('ses_');
});

it('hands a message to the queue and answers with a run id', function (): void {
    CrmAgent::fake(['Looking.']);

    $this->postJson('/agent/messages', ['message' => 'Which deals are stale?'])
        ->assertStatus(202)
        ->assertJsonStructure(['run_id']);
});

it('refuses a second message while the agent is still working', function (): void {
    CrmAgent::fake(['ok']);

    $this->getJson('/agent/thread');
    $session = Thread::query()->firstOrFail()->session;

    \Clutch\Laravel\Facades\Clutch::coordinator()->createRun($session, 'still going');

    $this->postJson('/agent/messages', ['message' => 'and another thing'])
        ->assertStatus(409);
});

it('keeps one thread across several messages', function (): void {
    CrmAgent::fake(['First.', 'Second.']);

    $this->postJson('/agent/messages', ['message' => 'Who owns IN-401?'])->assertStatus(202);
    $this->postJson('/agent/messages', ['message' => 'And its value?'])->assertStatus(202);

    expect(Run::query()->count())->toBe(2)
        ->and(Run::query()->distinct()->pluck('session_id'))->toHaveCount(1);
});

// Human in the loop -----------------------------------------------------------

it('stops for approval before discounting a deal', function (): void {
    CrmAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'ApplyDiscount', [
                'reference' => 'SO-233', 'percent' => 15, 'justification' => 'Small team',
            ], 'Discounts change contracted revenue.'),
        ]),
    ]);

    $this->postJson('/agent/messages', ['message' => 'Discount SO-233 by 15%']);

    expect(Run::query()->firstOrFail()->status)->toBe(RunStatus::AwaitingApproval);

    // Nothing was discounted while it waits.
    expect(Deal::query()->where('reference', 'SO-233')->firstOrFail()->discount_percent)->toBeNull();

    expect(Approval::query()->firstOrFail()->arguments['percent'])->toBe(15);
});

it('resumes when the approval is granted in a separate request', function (): void {
    CrmAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'ApplyDiscount', ['reference' => 'SO-233'], 'Cannot be undone.'),
        ]),
        'Applied.',
    ]);

    $this->postJson('/agent/messages', ['message' => 'Discount it']);

    $approval = Approval::query()->firstOrFail();

    $this->postJson("/agent/approvals/{$approval->id}", ['decision' => 'approve'])
        ->assertOk()
        ->assertJsonPath('status', ApprovalStatus::Approved->value);
});

it('sends a rejection back to the agent rather than killing the run', function (): void {
    CrmAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'ApplyDiscount', ['reference' => 'SO-233'], 'Cannot be undone.'),
        ]),
        'Understood, I will hold off.',
    ]);

    $this->postJson('/agent/messages', ['message' => 'Discount it']);
    $approval = Approval::query()->firstOrFail();

    $this->postJson("/agent/approvals/{$approval->id}", [
        'decision' => 'reject',
        'reason' => 'Too early in the cycle.',
    ])->assertOk();

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and(Run::query()->firstOrFail()->refresh()->status)->not->toBe(RunStatus::Failed);
});

// The protections -------------------------------------------------------------

it('routes tool calls through the ledger', function (): void {
    CrmAgent::fake(['ok']);

    $this->postJson('/agent/messages', ['message' => 'Find Initech']);
    $run = Run::query()->firstOrFail();

    withRunContext($run, function (): void {
        $tools = collect((new CrmAgent)->tools())->keyBy(fn ($t) => $t->name());

        $tools['SearchCrm']->handle(new \Laravel\Ai\Tools\Request(['query' => 'Initech'], 'call_1'));
    });

    // Nothing wrote to this table before the tools were wrapped.
    expect(ToolExecution::query()->where('tool_name', 'SearchCrm')->count())->toBe(1);
});

it('discounts once however many times the tool is delivered', function (): void {
    CrmAgent::fake(['ok']);

    $this->postJson('/agent/messages', ['message' => 'hello']);
    $run = Run::query()->firstOrFail();

    withRunContext($run, function (): void {
        $tools = collect((new CrmAgent)->tools())->keyBy(fn ($t) => $t->name());
        $discount = $tools['ApplyDiscount'];

        // Three deliveries of one side effect, each with a fresh call id,
        // which is exactly what a crash and retry produce.
        foreach (['a', 'b', 'c'] as $callId) {
            $discount->handle(new \Laravel\Ai\Tools\Request([
                'reference' => 'SO-233', 'percent' => 15, 'justification' => 'small team',
            ], $callId));
        }
    });

    $deal = Deal::query()->where('reference', 'SO-233')->firstOrFail();

    expect($deal->discount_attempts)->toBe(1)
        ->and($deal->discount_percent)->toBe(15);
});

it('recovers a run whose worker was killed', function (): void {
    CrmAgent::fake(['ok', 'ok']);

    $this->postJson('/agent/messages', ['message' => 'hello']);
    $run = Run::query()->firstOrFail();

    $this->postJson("/chaos/{$run->id}/kill")->assertOk();
    $this->postJson('/chaos/reap')->assertOk();

    expect($run->refresh()->status)->toBe(RunStatus::Failed)
        ->and(Run::query()->where('retry_of_run_id', $run->id)->firstOrFail()->attempt)->toBe(2);
});

it('never touches the CRM without being asked', function (): void {
    CrmAgent::fake(['ok']);

    $this->postJson('/agent/messages', ['message' => 'hello']);

    expect(Activity::query()->where('by_agent', true)->count())->toBe(0)
        ->and(Deal::query()->whereNotNull('discount_percent')->count())->toBe(0);
});
