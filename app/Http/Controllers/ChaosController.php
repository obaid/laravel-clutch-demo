<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Deal;
use Clutch\Laravel\Enums\RunStatus;
use Clutch\Laravel\Jobs\ReapAbandonedRuns;
use Clutch\Laravel\Models\Run;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The buttons that try to break a run.
 *
 * Every action here simulates something that happens in production and would
 * quietly corrupt a naive implementation.
 */
class ChaosController extends Controller
{
    /**
     * Pretend the worker was killed mid-run.
     *
     * Leaves the run claiming to be running with a stale heartbeat, which is
     * exactly the state a SIGKILL leaves behind: no failure was ever recorded,
     * because there was nobody left to record it.
     */
    public function killWorker(string $runId): JsonResponse
    {
        $run = Run::query()->findOrFail($runId);

        $run->forceFill([
            'status' => RunStatus::Running,
            'started_at' => now()->subHour(),
            'heartbeat_at' => now()->subHour(),
        ])->save();

        return response()->json([
            'message' => 'Worker killed. The run still claims to be running with a stale heartbeat, which is the state a SIGKILL leaves behind.',
        ]);
    }

    /**
     * Run the sweep that finds abandoned work.
     *
     * Fails the orphaned run and queues a fresh attempt from its last
     * checkpoint. The publish counter on the post is the thing to watch.
     */
    public function reap(): JsonResponse
    {
        dispatch_sync(new ReapAbandonedRuns(staleAfterSeconds: 60, retry: true));

        return response()->json([
            'message' => 'Reaper swept. Any abandoned run was failed and retried from its last checkpoint.',
        ]);
    }

    /**
     * Ask a running run to stop.
     */
    public function cancel(Request $request, string $runId): JsonResponse
    {
        Run::query()->findOrFail($runId)->cancel(
            $request->string('reason')->toString() ?: 'Cancelled from the support desk.'
        );

        return response()->json([
            'message' => 'Cancellation requested. No new step or tool will start.',
        ]);
    }

    /**
     * Deliver the same discount twice more, by hand.
     *
     * Two fresh tool-call IDs for one side effect, which is exactly what a
     * crash and retry produce. The ledger refuses both without the tool body
     * running, and the counter on the deal is the proof.
     */
    public function doubleDiscount(Request $request, string $runId): JsonResponse
    {
        $run = Run::query()->findOrFail($runId);

        $deal = Deal::query()->whereNotNull('discount_percent')->latest('updated_at')->first();

        if (! $deal) {
            return response()->json([
                'message' => 'No deal has been discounted yet, so there is nothing to double.',
            ], 422);
        }

        $before = $deal->discount_attempts;

        $ledger = app(\Clutch\Laravel\Tools\ToolExecutionLedger::class);
        $tool = new \App\Ai\Tools\ApplyDiscount;

        foreach (['manual_a', 'manual_b'] as $callId) {
            $invocation = new \Clutch\Laravel\Data\ToolInvocation(
                sessionId: $run->session_id,
                runId: $run->id,
                toolCallId: $callId,
                toolName: 'apply_discount',
                arguments: ['reference' => $deal->reference],
            );

            $ledger->guard($invocation, $tool, fn (): string => (string) $tool->handle(
                new \Laravel\Ai\Tools\Request([
                    'reference' => $deal->reference,
                    'percent' => 40,
                    'justification' => 'manual replay',
                ], $callId)
            ));
        }

        $extra = $deal->refresh()->discount_attempts - $before;

        return response()->json([
            'message' => "Delivered the discount on {$deal->reference} twice more. The tool body ran {$extra} extra time(s), not 2.",
        ]);
    }

    // Workflow variants. These redirect rather than returning JSON, because
    // they are pressed from the workflow page and the point is watching the
    // passes above change.

    public function wfKill(string $runId)
    {
        $this->killWorker($runId);

        return back()->with('chaos', 'Worker killed. The run still claims to be running with a dead heartbeat.');
    }

    public function wfReap(string $runId)
    {
        $this->reap();

        return back()->with('chaos', 'Reaper swept. An abandoned run was retried from its last checkpoint.');
    }

    /**
     * Re-enter a run that already finished or failed.
     *
     * Nothing is recomputed: every step that had a stored result replays, so
     * the only work that happens is whatever had not happened yet.
     */
    public function wfRetry(string $runId)
    {
        $run = Run::query()->findOrFail($runId);

        $coordinator = app(\Clutch\Laravel\Runtime\RunCoordinator::class);
        $retried = $coordinator->retryRun($run);

        return redirect()->route('workflows.show', $retried->id)
            ->with('chaos', 'Retried. Watch which steps come back as skipped.');
    }

    public function wfCancel(string $runId)
    {
        $run = Run::query()->findOrFail($runId);

        if (! $run->status->isTerminal()) {
            app(\Clutch\Laravel\Runtime\RunCoordinator::class)
                ->requestCancellation($run, 'Cancelled from the demo.');
        }

        return back()->with('chaos', 'Cancellation requested. It stops at the next step boundary, never mid-step.');
    }
}
