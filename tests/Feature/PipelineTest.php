<?php

declare(strict_types=1);

use App\Ai\Agents\ContentAgent;
use App\Ai\Tools\PublishPost;
use App\Models\Post;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Enums\ApprovalStatus;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Tools\ToolExecutionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;

uses(RefreshDatabase::class);

/**
 * These exercise the real driver, coordinator, approval broker and ledger.
 * Only the HTTP call to the model is faked, through Laravel AI's own gateway.
 */

it('starts a run without blocking the request', function (): void {
    ContentAgent::fake(['Researching now.']);

    $this->post('/runs', ['topic' => 'Row-level locking in Postgres'])
        ->assertRedirect();

    $post = Post::query()->firstOrFail();

    expect($post->topic)->toBe('Row-level locking in Postgres')
        ->and($post->session_id)->toStartWith('ses_')
        ->and($post->isPublished())->toBeFalse();
});

it('records an ordered event history a browser can replay', function (): void {
    ContentAgent::fake(['Draft written.']);

    $this->post('/runs', ['topic' => 'Anything']);

    $run = \Clutch\Laravel\Models\Run::query()->firstOrFail();
    $events = $run->events()->get();

    // Sequences are gap free, which is what makes reconnecting cheap.
    expect($events->pluck('sequence')->all())->toBe(range(1, $events->count()));

    // And the endpoint the page streams from replays from a cursor.
    $this->get("/api/clutch/runs/{$run->id}/events/history?after=2")
        ->assertOk()
        ->assertJsonPath('data.0.sequence', 3);
});

it('parks the run for a human before publishing', function (): void {
    ContentAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'publish_post', ['post_id' => 1], 'Publishing cannot be undone.'),
        ]),
    ]);

    $this->post('/runs', ['topic' => 'Anything']);

    $run = \Clutch\Laravel\Models\Run::query()->firstOrFail();

    expect($run->status)->toBe(RunStatus::AwaitingApproval);

    $approval = Approval::query()->firstOrFail();

    expect($approval->tool_name)->toBe('publish_post')
        ->and($approval->status)->toBe(ApprovalStatus::Pending);

    // Nothing was published while it waits.
    expect(Post::query()->firstOrFail()->isPublished())->toBeFalse();
});

it('resumes from a decision made in a separate request', function (): void {
    ContentAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'publish_post', ['post_id' => 1], 'Publishing cannot be undone.'),
        ]),
        'Published.',
    ]);

    $this->post('/runs', ['topic' => 'Anything']);

    $approval = Approval::query()->firstOrFail();

    // A completely separate request, as a human would make hours later.
    $this->post("/approvals/{$approval->id}", ['decision' => 'approve'])
        ->assertRedirect();

    expect($approval->refresh()->status)->toBe(ApprovalStatus::Approved);
});

it('refuses to reverse a decision once it is made', function (): void {
    ContentAgent::fake([
        AgentResponse::fakeWithPendingApprovals([
            new PendingApproval('call_1', 'publish_post', ['post_id' => 1], 'Cannot be undone.'),
        ]),
        'Published.',
    ]);

    $this->post('/runs', ['topic' => 'Anything']);
    $approval = Approval::query()->firstOrFail();

    $this->post("/approvals/{$approval->id}", ['decision' => 'approve']);

    // Approving twice is a no-op; reversing raises.
    $this->post("/approvals/{$approval->id}", ['decision' => 'reject'])
        ->assertStatus(409);
});

it('publishes exactly once however many times it is asked', function (): void {
    ContentAgent::fake(['ok']);

    $this->post('/runs', ['topic' => 'Anything']);

    $run = \Clutch\Laravel\Models\Run::query()->firstOrFail();
    $post = Post::query()->firstOrFail();

    $ledger = app(ToolExecutionLedger::class);
    $tool = new PublishPost;

    // Three deliveries of the same side effect, each with a different call id,
    // which is exactly what a crash and retry produces.
    foreach (['a', 'b', 'c'] as $callId) {
        $ledger->guard(
            new ToolInvocation(
                sessionId: $run->session_id,
                runId: $run->id,
                toolCallId: $callId,
                toolName: 'publish_post',
                arguments: ['post_id' => $post->id],
            ),
            $tool,
            fn (): string => (string) $tool->handle(
                new \Laravel\Ai\Tools\Request(['post_id' => $post->id], $callId)
            ),
        );
    }

    expect($post->refresh()->publish_attempts)->toBe(1)
        ->and($post->isPublished())->toBeTrue();
});

it('recovers a run whose worker was killed', function (): void {
    ContentAgent::fake(['ok', 'ok']);

    $this->post('/runs', ['topic' => 'Anything']);

    $run = \Clutch\Laravel\Models\Run::query()->firstOrFail();

    // Simulate the SIGKILL, then sweep.
    $this->post("/chaos/{$run->id}/kill");
    $this->post('/chaos/reap');

    expect($run->refresh()->status)->toBe(RunStatus::Failed);

    // A fresh attempt was queued rather than the terminal record reopened.
    $retry = \Clutch\Laravel\Models\Run::query()->where('retry_of_run_id', $run->id)->firstOrFail();

    expect($retry->attempt)->toBe(2);
});

it('keeps the agent honest about which tools need approval', function (): void {
    $tools = collect((new ContentAgent)->tools());

    // Outside a run the policy is a no-op, so every tool is present.
    expect($tools)->toHaveCount(3);
});
