@include('crm._head', ['title' => 'Quarterly review', 'meta' => $run->id])

<div class="px-6 py-5" id="wf" data-run="{{ $run->id }}" data-state-url="{{ route('workflows.state', $run->id) }}">

    <div class="flex items-center gap-3 mb-5">
        <span id="wf-status">@include('workflows._status', ['status' => $run->status->value])</span>
        <a href="{{ route('workflows.index') }}" class="text-[12px] mute hover:underline">All runs</a>

        @isset($retryOf)
            <span class="text-[12px] mute">
                attempt {{ $run->attempt }} ·
                <a href="{{ route('workflows.show', $retryOf) }}" class="hover:underline"
                   style="color:var(--blue)">the attempt before this one</a>
            </span>
        @endisset
    </div>

    @if ($retriedAs)
        <div class="rounded border mb-5 px-4 py-3 text-[13px]"
             style="background:var(--blue-soft);border-color:var(--blue)">
            This attempt was abandoned, so the harness started another one.
            <a href="{{ route('workflows.show', $retriedAs->id) }}" class="font-semibold hover:underline"
               style="color:var(--blue)">Open attempt {{ $retriedAs->attempt }}</a>
            to see it pick up from the last checkpoint with every finished step skipped.
        </div>
    @endif

    {{-- The plan. Every step the job can take, so what has not happened yet is
         as visible as what has. --}}
    <div class="rounded border hair overflow-hidden mb-5" style="background:var(--card)">
        <div class="px-4 py-2.5 border-b hair text-[11px] uppercase tracking-wide ash font-semibold"
             style="background:var(--soft)">The plan</div>
        <div id="wf-steps" class="divide-y" style="border-color:var(--hair-soft)">
            @foreach ($steps as $i => $step)
                <div class="wf-step flex items-start gap-3 px-4 py-3" data-step="{{ $step['key'] }}">
                    <div class="wf-dot mt-0.5 shrink-0 w-5 h-5 rounded-full border-2 flex items-center justify-center text-[10px] font-bold"></div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="text-[13px] font-semibold ink">{{ $step['label'] }}</span>
                            <span class="wf-badge text-[10px] font-semibold uppercase tracking-wide"></span>
                        </div>
                        <div class="text-[12px] mute">{{ $step['detail'] }}</div>
                        <div class="wf-value mono text-[11px] mt-1" style="color:var(--blue)"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- The approval, if the run is parked. --}}
    <div id="wf-approval" class="{{ $approval ? '' : 'hidden' }} rounded border mb-5 p-4"
         style="background:#fdf9ef;border-color:var(--primary)">
        <div class="text-[11px] uppercase tracking-wide font-semibold mb-2" style="color:var(--primary-press)">
            Waiting for you
        </div>
        <p class="text-[13px] body mb-3">
            Everything up to here was reversible, so none of it asked. This is the first thing
            that reaches a customer. The worker has already exited: nothing is holding a
            connection while this sits here.
        </p>
        <pre id="wf-approval-body" class="mono text-[11px] rounded p-3 overflow-x-auto mb-3"
             style="background:var(--dark);color:#e8e8e2;max-height:260px">{{ $approval ? json_encode($approval->arguments, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) : '' }}</pre>
        <div class="flex gap-2">
            <form method="POST" action="{{ route('workflows.decide', $run->id) }}">
                @csrf<input type="hidden" name="approved" value="1">
                <button class="rounded px-3 py-1.5 text-[13px] font-semibold"
                        style="background:var(--primary);color:var(--dark)">Send them</button>
            </form>
            <form method="POST" action="{{ route('workflows.decide', $run->id) }}" class="flex gap-2">
                @csrf<input type="hidden" name="approved" value="0">
                <input name="reason" placeholder="Why not?" class="rounded border hair px-2 py-1 text-[13px]"
                       style="background:var(--doc)">
                <button class="rounded border hair px-3 py-1.5 text-[13px] font-semibold ink"
                        style="background:var(--card)">Decline</button>
            </form>
        </div>
    </div>

    {{-- The demonstration. What each entry into handle() actually executed. --}}
    <div class="rounded border hair overflow-hidden mb-5" style="background:var(--card)">
        <div class="px-4 py-2.5 border-b hair" style="background:var(--soft)">
            <div class="text-[11px] uppercase tracking-wide ash font-semibold">Passes through handle()</div>
            <div class="text-[12px] mute mt-0.5">
                The body runs from the top every time. A step runs once, ever, so the second
                pass replays what the first already did instead of paying for it again.
            </div>
        </div>
        <div id="wf-passes" class="p-4 space-y-3"></div>
    </div>

    {{-- The point of these is that none of them cost you a step twice. --}}
    <details class="rounded border hair mb-5" style="background:var(--card)">
        <summary class="px-4 py-2.5 text-[11px] uppercase tracking-wide ash font-semibold cursor-pointer"
                 style="background:var(--soft)">Try to break it</summary>
        <div class="p-4 space-y-3">
            <p class="text-[13px] body">
                Each of these interrupts the run somewhere different. Watch the passes above
                afterwards: the steps that had already finished come back as skipped rather
                than running again, and the emails are still sent exactly once.
            </p>
            <div class="flex flex-wrap gap-2">
                @foreach ([
                    ['chaos.wf.kill', 'Kill the worker', 'Drops the run to running with a dead heartbeat'],
                    ['chaos.wf.reap', 'Run the reaper', 'Finds it and retries from the last checkpoint'],
                    ['chaos.wf.retry', 'Retry the run', 'Re-enters, keeping every finished step'],
                    ['chaos.wf.cancel', 'Cancel it', 'Stops at the next step boundary, not mid-step'],
                ] as [$route, $label, $detail])
                    <form method="POST" action="{{ route($route, $run->id) }}">
                        @csrf
                        <button class="rounded border hair px-3 py-1.5 text-[13px] font-semibold ink text-left"
                                style="background:var(--doc)" title="{{ $detail }}">{{ $label }}</button>
                    </form>
                @endforeach
            </div>
            <div class="text-[12px] mute">
                Emails sent for this run so far:
                <span class="mono font-semibold" style="color:var(--green)">{{ $emailCount }}</span>
                — one per drafted deal, however many times you interrupt it.
            </div>
        </div>
    </details>

    <div class="grid grid-cols-2 gap-5">
        <div class="rounded border hair overflow-hidden" style="background:var(--card)">
            <div class="px-4 py-2.5 border-b hair text-[11px] uppercase tracking-wide ash font-semibold"
                 style="background:var(--soft)">Event log</div>
            <div id="wf-events" class="p-3 mono text-[11px] space-y-0.5 overflow-y-auto" style="max-height:320px"></div>
        </div>

        <div class="space-y-5">
            <div class="rounded border hair overflow-hidden" style="background:var(--card)">
                <div class="px-4 py-2.5 border-b hair text-[11px] uppercase tracking-wide ash font-semibold"
                     style="background:var(--soft)">Artifacts</div>
                <div id="wf-artifacts" class="p-3 text-[12px] mute">None yet.</div>
            </div>

            <div class="rounded border hair overflow-hidden" style="background:var(--card)">
                <div class="px-4 py-2.5 border-b hair text-[11px] uppercase tracking-wide ash font-semibold"
                     style="background:var(--soft)">Result</div>
                <pre id="wf-output" class="p-3 mono text-[11px] overflow-x-auto mute" style="max-height:220px">—</pre>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const root = document.getElementById('wf');
    if (!root || root.dataset.wired) return;
    root.dataset.wired = '1';

    const LOOK = {
        done:    { border: 'var(--green)',  bg: 'var(--green)',  mark: '✓', label: 'done',    color: 'var(--green)' },
        running: { border: 'var(--blue)',   bg: 'transparent',   mark: '',  label: 'running', color: 'var(--blue)' },
        waiting: { border: 'var(--primary)',bg: 'var(--primary)',mark: '?', label: 'waiting', color: 'var(--primary-press)' },
        pending: { border: 'var(--stone)',  bg: 'transparent',   mark: '',  label: '',        color: 'var(--ash)' },
    };

    function paint(state) {
        document.getElementById('wf-status').innerHTML = badge(state.status);

        state.steps.forEach(step => {
            const el = root.querySelector(`.wf-step[data-step="${step.key}"]`);
            if (!el) return;
            const look = LOOK[step.status] || LOOK.pending;
            const dot = el.querySelector('.wf-dot');
            dot.style.borderColor = look.border;
            dot.style.background = look.bg;
            dot.style.color = '#fff';
            dot.textContent = look.mark;
            const badgeEl = el.querySelector('.wf-badge');
            badgeEl.textContent = look.label;
            badgeEl.style.color = look.color;
            el.querySelector('.wf-value').textContent = step.value || '';
        });

        const passes = document.getElementById('wf-passes');
        passes.innerHTML = state.passes.length === 0
            ? '<div class="text-[12px] mute">Nothing yet.</div>'
            : state.passes.map((p, i) => `
                <div class="flex gap-3 items-start">
                    <div class="mono text-[11px] ash shrink-0 pt-0.5" style="width:56px">pass ${i + 1}</div>
                    <div class="flex flex-wrap gap-1.5">
                        ${p.replayed.map(s => chip(s, 'skipped', 'var(--mute)', 'var(--soft)')).join('')}
                        ${p.ran.map(s => chip(s, 'ran', 'var(--green)', 'var(--green-soft)')).join('')}
                        ${p.ran.length === 0 && p.replayed.length === 0 ? '<span class="text-[12px] mute">—</span>' : ''}
                    </div>
                </div>`).join('');

        const events = document.getElementById('wf-events');
        events.innerHTML = state.events.map(e => {
            const replayed = e.replayed === true;
            return `<div style="color:${replayed ? 'var(--ash)' : 'var(--body)'}">
                <span class="ash">${e.at || ''}</span>
                ${e.type}${e.step ? ' · ' + e.step : ''}${replayed ? ' (skipped)' : ''}
            </div>`;
        }).join('');
        events.scrollTop = events.scrollHeight;

        const art = document.getElementById('wf-artifacts');
        art.innerHTML = state.artifacts.length === 0
            ? 'None yet.'
            : state.artifacts.map(a => `<div class="mono text-[11px]">${a.name} <span class="ash">${a.bytes}b</span></div>`).join('');

        document.getElementById('wf-output').textContent = state.output
            ? JSON.stringify(state.output, null, 2)
            : '—';

        const approval = document.getElementById('wf-approval');
        if (state.approval) {
            approval.classList.remove('hidden');
            document.getElementById('wf-approval-body').textContent =
                JSON.stringify(state.approval.arguments, null, 2);
        } else {
            approval.classList.add('hidden');
        }

        return state.finished;
    }

    function chip(text, suffix, fg, bg) {
        return `<span class="rounded px-1.5 py-0.5 text-[11px] mono" style="color:${fg};background:${bg}">
            ${text}<span class="opacity-60"> ${suffix}</span></span>`;
    }

    function badge(status) {
        const map = {
            completed: ['Completed', 'var(--green)', 'var(--green-soft)'],
            awaiting_approval: ['Waiting for you', 'var(--primary-press)', '#fdf0d0'],
            running: ['Running', 'var(--blue)', 'var(--blue-soft)'],
            queued: ['Queued', 'var(--mute)', 'var(--soft)'],
            failed: ['Failed', '#c0392b', '#f8dcd8'],
            cancelled: ['Cancelled', 'var(--mute)', 'var(--soft)'],
        };
        const [label, fg, bg] = map[status] || [status, 'var(--mute)', 'var(--soft)'];
        return `<span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold"
                      style="color:${fg};background:${bg}">${label}</span>`;
    }

    // Polled rather than streamed: a workflow's interesting moments are steps
    // landing, which is a far slower cadence than a token stream.
    let timer = null;
    function tick() {
        fetch(root.dataset.stateUrl, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(state => {
                const finished = paint(state);
                // Keep polling while parked: the decision may come from
                // somewhere else entirely, and this page should notice.
                if (finished && state.status !== 'awaiting_approval') clearInterval(timer);
            })
            .catch(() => {});
    }

    tick();
    timer = setInterval(tick, 1200);

    // The panel swaps this pane out on navigation; stop polling when it goes.
    new MutationObserver(() => {
        if (!document.body.contains(root)) clearInterval(timer);
    }).observe(document.body, { childList: true, subtree: true });
})();
</script>
