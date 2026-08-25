<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\CrmAgent;
use App\Models\Thread;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\ValueObjects\RunBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The agent panel. Everything here is JSON, because the panel never reloads.
 */
class AgentController extends Controller
{
    /**
     * The thread the panel is attached to, creating one on first use.
     */
    public function thread(): JsonResponse
    {
        $thread = Thread::query()->latest()->first() ?? $this->open();

        $runs = Run::query()
            ->where('session_id', $thread->session_id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'thread_id' => $thread->id,
            'title' => $thread->title,
            // Enough for the panel to rebuild itself after a hard reload: the
            // conversation lives in the event log, not in the browser.
            'runs' => $runs->map(fn (Run $r): array => [
                'id' => $r->id,
                'prompt' => $r->promptText(),
                'status' => $r->status->value,
                'finished' => $r->status->isTerminal(),
            ]),
        ]);
    }

    public function reset(): JsonResponse
    {
        $thread = $this->open();

        return response()->json(['thread_id' => $thread->id]);
    }

    /**
     * Send a message and hand the work to a queue worker.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $thread = Thread::query()->latest()->firstOr(fn (): Thread => $this->open());
        $session = Session::query()->findOrFail($thread->session_id);

        // One active run per session, so a second message while the agent is
        // mid-thought is refused rather than interleaved.
        if ($session->hasActiveRun()) {
            return response()->json([
                'error' => 'The agent is still working on the previous message.',
                'run_id' => $session->active_run_id,
            ], 409);
        }

        $thread->title ??= \Illuminate\Support\Str::limit($validated['message'], 60);
        $thread->save();

        return response()->json([
            'run_id' => $session->queue($validated['message'])->id,
        ], 202);
    }

    /**
     * Record a decision and let the run carry on.
     *
     * The panel keeps its event stream open across this, because an approval
     * pause is not terminal. The continuation arrives on the same connection.
     */
    public function decide(Request $request, string $approvalId): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $approval = Approval::query()->findOrFail($approvalId);
        $run = Clutch::run($approval->run_id);
        $reason = $validated['reason'] ?? null;

        $validated['decision'] === 'approve'
            ? $run->approve($approval->id, $reason)
            : $run->reject($approval->id, $reason ?: 'Declined by the rep.');

        Clutch::coordinator()->resumeAfterApproval($run->refresh());

        return response()->json([
            'status' => $approval->refresh()->status->value,
            'run_status' => $run->refresh()->status->value,
        ]);
    }

    protected function open(): Thread
    {
        $session = Clutch::agent(CrmAgent::class)
            ->name('CRM assistant')
            ->permissions(PermissionMode::ApproveSensitive)
            ->budget(new RunBudget(
                maxSteps: 25,
                maxToolCalls: 20,
                maxTokens: 250_000,
                maxCostUsd: 1.00,
                maxDurationSeconds: 600,
            ))
            ->create();

        return Thread::query()->create(['session_id' => $session->id]);
    }
}
