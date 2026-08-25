<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Pipeline' }} · Clutch CRM</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* PostHog's palette: a warm cream canvas rather than the usual dark
           tech look, thin hairlines instead of shadows, one loud accent. */
        :root {
            --canvas:#eeefe9; --soft:#e5e7e0; --card:#ffffff; --doc:#fcfcfa; --dark:#23251d;
            --ink:#23251d; --body:#4d4f46; --mute:#6c6e63; --ash:#9b9c92; --stone:#b6b7af;
            --hair:#bfc1b7; --hair-soft:#dcdfd2;
            --primary:#f7a501; --primary-press:#dd9001;
            --blue:#2c84e0; --blue-soft:#dceaf6;
            --green:#2c8c66; --green-soft:#d9eddf;
            --red:#cd4239; --red-soft:#f7d6d3;
            --purple:#7c44a6; --purple-soft:#e7d8ee;
        }
        body { background:var(--canvas); color:var(--body); font-family:'IBM Plex Sans',-apple-system,system-ui,sans-serif; }
        .mono, code, pre { font-family:'IBM Plex Mono',ui-monospace,monospace; }
        .card { background:var(--card); border:1px solid var(--hair); border-radius:6px; }
        .hair { border-color:var(--hair); }
        .ink { color:var(--ink); }
        .mute { color:var(--mute); }
        .ash { color:var(--ash); }
        /* No drop shadows anywhere. Thin borders carry the whole hierarchy. */
        ::-webkit-scrollbar { width:10px; height:10px }
        ::-webkit-scrollbar-thumb { background:var(--stone); border-radius:5px }
        ::-webkit-scrollbar-track { background:transparent }
        .spin { animation:spin 1s linear infinite } @keyframes spin { to { transform:rotate(360deg) } }
        .nav-on { background:var(--soft); color:var(--ink); font-weight:600 }
    </style>
</head>
<body class="h-screen flex overflow-hidden text-[15px]">

{{-- Left: navigation --}}
<nav class="w-52 shrink-0 border-r hair flex flex-col">
    <div class="px-4 py-3.5 border-b hair flex items-center gap-2">
        <span class="w-5 h-5 rounded" style="background:var(--primary)"></span>
        <span class="font-bold ink text-[15px]">Clutch CRM</span>
    </div>

    <div class="flex-1 p-2 space-y-0.5 text-sm">
        @foreach ([
            ['pipeline','Pipeline'], ['deals','Deals'], ['companies','Companies'],
            ['contacts','Contacts'], ['activity','Activity'],
        ] as [$route, $label])
            <a href="{{ route($route) }}" data-nav="{{ $route }}"
               class="nav block rounded px-2.5 py-1.5 hover:bg-[var(--soft)] transition-colors">{{ $label }}</a>
        @endforeach
    </div>

    <div class="p-3 border-t hair text-[11px] ash leading-relaxed">
        A demo of <a href="https://github.com/obaid/laravel-clutch" class="underline" style="color:var(--blue)">Laravel Clutch</a>.
        The agent runs on a queue, so it survives you closing this tab.
    </div>
</nav>

{{-- Middle: the only part that changes between pages --}}
<main id="main" class="flex-1 min-w-0 overflow-y-auto">
    @include($pane)
</main>

{{-- Right: the agent, full height, never reloaded --}}
<aside class="w-[400px] shrink-0 border-l hair flex flex-col" style="background:var(--doc)">
    <div class="px-4 py-3 border-b hair flex items-center justify-between shrink-0">
        <div class="flex items-center gap-2">
            <span class="w-2 h-2 rounded-full" style="background:var(--green)"></span>
            <span class="font-semibold ink text-sm">Assistant</span>
        </div>
        <div class="flex items-center gap-2 text-[11px] ash">
            <span id="provider"></span>
            <button id="reset" class="rounded px-2 py-1 hover:bg-[var(--soft)]">New thread</button>
        </div>
    </div>

    <div id="log" class="flex-1 overflow-y-auto px-4 py-4 space-y-3 text-sm"></div>

    <div class="shrink-0 border-t hair p-3" style="background:var(--card)">
        <div class="flex gap-2">
            <input id="input" placeholder="Ask about the pipeline, or tell it what to do"
                   class="flex-1 rounded border hair px-3 py-2 text-sm bg-white focus:outline-none focus:border-[var(--primary)]">
            <button id="send" class="rounded px-3.5 py-2 text-sm font-bold ink disabled:opacity-40"
                    style="background:var(--primary)">Send</button>
        </div>
        <div id="suggest" class="flex flex-wrap gap-1 mt-2">
            @foreach ([
                'Which deals have gone quiet?',
                'Marcus at Initech went quiet on IN-401. Chase it.',
                'Soylent asked for a discount on SO-233.',
            ] as $s)
                <button class="sg text-[11px] rounded border hair px-2 py-1 mute hover:border-[var(--primary)]">{{ $s }}</button>
            @endforeach
        </div>

        <details class="mt-2">
            <summary class="text-[11px] ash cursor-pointer select-none">Try to break it</summary>
            <div class="mt-1.5 space-y-1">
                <button data-chaos="{{ route('chaos.double', 'RUN') }}" class="cx w-full text-left text-[11px] rounded border hair px-2 py-1.5 hover:border-[var(--red)]">
                    Deliver the discount twice more <span class="ash">· ledger keeps it at one</span>
                </button>
                <button data-chaos="{{ route('chaos.kill', 'RUN') }}" class="cx w-full text-left text-[11px] rounded border hair px-2 py-1.5 hover:border-[var(--red)]">
                    Kill the worker mid-run <span class="ash">· stale heartbeat</span>
                </button>
                <button data-chaos="{{ route('chaos.reap') }}" data-norun class="cx w-full text-left text-[11px] rounded border hair px-2 py-1.5 hover:border-[var(--green)]">
                    Run the reaper <span class="ash">· recovers from checkpoint</span>
                </button>
                <button data-chaos="{{ route('chaos.cancel', 'RUN') }}" class="cx w-full text-left text-[11px] rounded border hair px-2 py-1.5 hover:border-[var(--red)]">
                    Cancel the run
                </button>
                <div id="cxout" class="text-[11px]" style="color:var(--red)"></div>
            </div>
        </details>
    </div>
</aside>

<script>
@include('agent-panel')
</script>
</body>
</html>
