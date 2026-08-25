(function () {
    const log    = document.getElementById('log');
    const input  = document.getElementById('input');
    const send   = document.getElementById('send');
    const main   = document.getElementById('main');

    let activeRun = null, source = null, bubble = null;
    const chips = new Map();
    const seen  = new Set();

    const csrf = () => document.querySelector('meta[name=csrf-token]').content;
    const esc  = (s) => String(s ?? '').replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c]));
    const el   = (h) => { const t = document.createElement('template'); t.innerHTML = h.trim(); return t.content.firstElementChild; };
    const scroll = () => log.scrollTop = log.scrollHeight;

    // ---- Navigation ---------------------------------------------------
    // Only the main pane is swapped. The panel sits outside it, so a run
    // being streamed is never interrupted by moving around the CRM.

    function markNav() {
        document.querySelectorAll('.nav').forEach(a => {
            const on = new URL(a.href).pathname === location.pathname;
            a.classList.toggle('nav-on', on);
        });
    }

    async function go(url, push = true) {
        const res = await fetch(url, { headers: { 'X-Pane': 'main' } });
        if (!res.ok) { location.href = url; return; }
        main.innerHTML = await res.text();
        main.scrollTop = 0;
        if (push) history.pushState({}, '', url);
        markNav();
    }

    document.addEventListener('click', (e) => {
        const a = e.target.closest('a[href]');
        if (!a) return;
        const url = new URL(a.href);
        if (url.origin !== location.origin || a.target === '_blank' || url.pathname.startsWith('/api')) return;
        e.preventDefault();
        go(url.pathname + url.search);
    });

    window.addEventListener('popstate', () => go(location.pathname + location.search, false));
    markNav();

    // Refresh the pane after the agent changes something behind it.
    const refreshMain = () => go(location.pathname + location.search, false);

    // ---- The conversation ---------------------------------------------

    function userBubble(text) {
        log.appendChild(el(`<div class="flex justify-end"><div class="max-w-[85%] rounded px-3 py-2 text-sm" style="background:var(--soft);color:var(--ink)">${esc(text)}</div></div>`));
        scroll();
    }

    function assistantBubble() {
        const w = el(`<div class="space-y-1.5"><div class="tools flex flex-wrap gap-1"></div><div class="body card px-3 py-2 text-sm whitespace-pre-wrap hidden" style="color:var(--body)"></div></div>`);
        log.appendChild(w); scroll(); return w;
    }

    function chip(id, name, args) {
        const c = el(`<span class="mono text-[11px] rounded border hair px-1.5 py-0.5 inline-flex items-center gap-1" style="background:var(--card);color:var(--mute)">
            <span class="dot w-2 h-2 rounded-full border spin" style="border-color:var(--primary);border-top-color:transparent"></span>
            <span>${esc(name)}</span></span>`);
        if (args) c.title = JSON.stringify(args, null, 2);
        (bubble ??= assistantBubble()).querySelector('.tools').appendChild(c);
        chips.set(id, c); scroll();
    }

    function chipDone(id, ok, result) {
        const c = chips.get(id); if (!c) return;
        c.querySelector('.dot')?.remove();
        c.style.color = ok ? 'var(--green)' : 'var(--red)';
        c.style.borderColor = ok ? 'var(--green)' : 'var(--red)';
        c.style.background = ok ? 'var(--green-soft)' : 'var(--red-soft)';
        c.insertAdjacentHTML('afterbegin', `<span>${ok ? '✓' : '✗'}</span>`);
        if (result) c.title = String(result).slice(0, 600);
        scroll();
    }

    // The approval lands inline, where the decision belongs.
    function approvalCard(p) {
        const card = el(`<div class="rounded border px-3 py-2.5 text-sm" style="border-color:var(--primary);background:#fdf6e3">
            <div class="text-[11px] font-bold uppercase tracking-wide mb-1" style="color:var(--primary-press)">Needs approval</div>
            <div class="mono text-[11px] mb-2 mute">${esc(p.tool)}</div>
            <pre class="text-[11px] rounded p-2 mb-2 overflow-x-auto" style="background:var(--dark);color:#fff">${esc(JSON.stringify(p.arguments ?? {}, null, 2))}</pre>
            <div class="acts flex gap-1.5">
              <input class="why flex-1 rounded border hair px-2 py-1 text-xs bg-white" placeholder="Reason (optional)">
              <button class="ok rounded px-2.5 py-1 text-xs font-bold ink" style="background:var(--primary)">Approve</button>
              <button class="no rounded border hair px-2.5 py-1 text-xs">Reject</button>
            </div></div>`);

        const decide = async (decision) => {
            card.querySelectorAll('button,input').forEach(b => b.disabled = true);
            const reason = card.querySelector('.why').value;
            await fetch(`/agent/approvals/${p.approval_id}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ decision, reason }),
            });
            card.querySelector('.acts').replaceWith(el(
                `<div class="text-xs" style="color:${decision === 'approve' ? 'var(--green)' : 'var(--red)'}">
                   ${decision === 'approve' ? 'Approved' : 'Rejected'}${reason ? ': ' + esc(reason) : ''}</div>`));
            // The stream is still open: an approval pause is not terminal, so
            // the continuation arrives on this same connection.
            bubble = assistantBubble();
        };

        card.querySelector('.ok').onclick = () => decide('approve');
        card.querySelector('.no').onclick = () => decide('reject');
        log.appendChild(card); scroll();
    }

    const note = (t, colour = 'var(--ash)') =>
        { log.appendChild(el(`<div class="text-[11px] text-center py-0.5" style="color:${colour}">${esc(t)}</div>`)); scroll(); };

    function handle(e) {
        const key = e.run_id + ':' + e.sequence;
        if (seen.has(key)) return;              // delivery is at least once
        seen.add(key);
        const p = e.payload || {};

        switch (e.type) {
            case 'run.started':          bubble ??= assistantBubble(); break;
            case 'text.delta': {
                const b = (bubble ??= assistantBubble()).querySelector('.body');
                b.classList.remove('hidden'); b.textContent += p.delta ?? ''; scroll(); break;
            }
            case 'tool.call.requested':  chip(p.tool_call_id, p.tool, p.arguments); break;
            case 'tool.call.completed':  chipDone(p.tool_call_id, true, p.result); refreshMain(); break;
            case 'tool.call.failed':     chipDone(p.tool_call_id, false, p.error); break;
            case 'approval.requested':   if (p.approval_id) approvalCard(p); break;
            case 'run.suspended':        note('parked at a boundary, resuming'); break;
            case 'run.completed':        finish(); break;
            case 'run.failed':           note(p.message || 'The run failed.', 'var(--red)'); finish(); break;
            case 'run.cancelled':        note('Cancelled.', 'var(--red)'); finish(); break;
            case 'run.budget_exceeded':  note('Stopped: ' + (p.limit || 'budget') + ' reached.', 'var(--red)'); finish(); break;
        }
    }

    function finish() {
        source?.close(); source = null; activeRun = null; bubble = null;
        send.disabled = input.disabled = false;
        refreshMain();
    }

    function lastSeq(runId) {
        let max = 0;
        for (const k of seen) { const [r, s] = k.split(':'); if (r === runId) max = Math.max(max, +s); }
        return max;
    }

    function follow(runId, after = 0) {
        activeRun = runId;
        send.disabled = input.disabled = true;
        source = new EventSource(`/api/clutch/runs/${runId}/events?after=${after}`);
        // Frames are unnamed, so onmessage receives every event.
        source.onmessage = (m) => {
            if (m.data === '[DONE]') { finish(); return; }
            try { handle(JSON.parse(m.data)); } catch (_) {}
        };
        source.addEventListener('timeout', () => { source.close(); follow(runId, lastSeq(runId)); });
        source.onerror = () => { source.close(); setTimeout(() => follow(runId, lastSeq(runId)), 2000); };
    }

    async function submit(text) {
        text = (text ?? input.value).trim();
        if (!text || send.disabled) return;
        input.value = '';
        userBubble(text);
        bubble = assistantBubble();
        send.disabled = input.disabled = true;

        const res = await fetch('/agent/messages', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ message: text }),
        });

        if (!res.ok) {
            note((await res.json().catch(() => ({}))).error ?? 'Could not send that.', 'var(--red)');
            send.disabled = input.disabled = false;
            return;
        }
        follow((await res.json()).run_id);
    }

    send.onclick = () => submit();
    input.onkeydown = (e) => { if (e.key === 'Enter') submit(); };
    document.querySelectorAll('.sg').forEach(b => b.onclick = () => submit(b.textContent.trim()));

    document.getElementById('reset').onclick = async () => {
        await fetch('/agent/reset', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() } });
        log.innerHTML = ''; seen.clear(); chips.clear(); bubble = null;
        note('New thread.');
    };

    document.querySelectorAll('.cx').forEach(b => b.onclick = async () => {
        const url = b.dataset.norun !== undefined
            ? b.dataset.chaos
            : b.dataset.chaos.replace('RUN', activeRun ?? lastRun ?? '');
        if (b.dataset.norun === undefined && !(activeRun ?? lastRun)) {
            document.getElementById('cxout').textContent = 'Send a message first.'; return;
        }
        const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf() } });
        document.getElementById('cxout').textContent = (await res.json().catch(() => ({}))).message ?? 'Done.';
        refreshMain();
    });

    // ---- Rejoin on load ------------------------------------------------
    // The conversation lives in the event log, so a hard reload can rebuild it
    // and rejoin a run that is still going.

    let lastRun = null;

    (async () => {
        const t = await (await fetch('/agent/thread')).json();
        const runs = t.runs ?? [];
        lastRun = runs.length ? runs[runs.length - 1].id : null;

        for (const r of runs) {
            userBubble(r.prompt);
            if (r.finished) {
                // Replay what was recorded rather than re-running anything.
                const h = await (await fetch(`/api/clutch/runs/${r.id}/events/history?after=0&limit=500`)).json();
                bubble = assistantBubble();
                for (const e of h.data) handle(e);
                bubble = null;
            }
        }

        const live = runs.find(r => !r.finished);
        if (live) { note('Rejoining a run already in progress'); bubble = assistantBubble(); follow(live.id, lastSeq(live.id)); }
    })();
})();
