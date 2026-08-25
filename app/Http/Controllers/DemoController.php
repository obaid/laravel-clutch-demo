<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\ContentAgent;
use App\Models\Post;
use Clutch\Laravel\Enums\PermissionMode;
use Clutch\Laravel\Facades\Clutch;
use Clutch\Laravel\Models\Approval;
use Clutch\Laravel\Models\Run;
use Clutch\Laravel\Models\Session;
use Clutch\Laravel\ValueObjects\RunBudget;
use Illuminate\Http\Request;

class DemoController extends Controller
{
    /**
     * Everything that has been run, and the form to start another.
     */
    public function index()
    {
        return view('demo.index', [
            'sessions' => Session::query()->latest()->limit(15)->get(),
            'pending' => Approval::query()->pending()->count(),
            'configured' => filled(config('ai.providers.anthropic.key')),
        ]);
    }

    /**
     * Start a run and return immediately.
     *
     * The HTTP request ends here. Everything after this point belongs to a
     * queue worker, which is the first thing the demo is trying to show.
     */
    public function start(Request $request)
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
        ]);

        $session = Clutch::agent(ContentAgent::class)
            ->name($validated['topic'])
            ->permissions(PermissionMode::ApproveSensitive)
            ->budget(new RunBudget(
                maxSteps: 20,
                maxToolCalls: 12,
                maxTokens: 200_000,
                maxCostUsd: 1.00,
                maxDurationSeconds: 600,
            ))
            ->create();

        // The agent finds its post by session, so it exists before the run does.
        Post::query()->create([
            'topic' => $validated['topic'],
            'session_id' => $session->id,
        ]);

        $run = $session->queue(
            "Research and write a post about: {$validated['topic']}"
        );

        return redirect()->route('demo.run', $run->id);
    }

    /**
     * Watch one run. The page streams, and survives being reloaded.
     */
    public function run(string $runId)
    {
        $run = Run::query()->with('session')->findOrFail($runId);

        return view('demo.run', [
            'run' => $run,
            'post' => Post::query()->where('session_id', $run->session_id)->first(),
            'approvals' => $run->approvals()->latest('requested_at')->get(),
            'artifacts' => $run->artifacts()->latest()->get(),
        ]);
    }

    /**
     * Everything waiting on a human, across every session.
     */
    public function approvals()
    {
        return view('demo.approvals', [
            'approvals' => Approval::query()
                ->pending()
                ->with('run')
                ->latest('requested_at')
                ->get(),
        ]);
    }

    /**
     * Record a decision and let the run carry on.
     *
     * Nothing here knows or cares which process started the run, which is the
     * point: this can be a different worker, a different machine, a week later.
     */
    public function decide(Request $request, string $approvalId)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $approval = Approval::query()->findOrFail($approvalId);
        $run = Clutch::run($approval->run_id);

        // A nullable field is absent from the validated array when it is not
        // sent at all, so this reads it defensively rather than by key.
        $reason = $validated['reason'] ?? null;

        $validated['decision'] === 'approve'
            ? $run->approve($approval->id, $reason)
            : $run->reject($approval->id, $reason ?: 'Rejected from the demo UI.');

        Clutch::coordinator()->resumeAfterApproval($run->refresh());

        return redirect()->route('demo.run', $run->id);
    }
}
