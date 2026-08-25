@extends('layout')
@section('title', 'Laravel Clutch demo')

@section('content')
    @unless ($configured)
        <div class="panel rounded-lg px-4 py-3 mb-6 border-l-2 border-l-rose-400 text-sm">
            No <code>ANTHROPIC_API_KEY</code> is set, so runs will fail at the provider.
            Add one to <code>.env</code> and restart the queue worker.
        </div>
    @endunless

    <div class="panel rounded-xl p-6 mb-8">
        <h1 class="text-lg font-semibold text-slate-100 mb-1">Research and publish a post</h1>
        <p class="text-sm text-slate-400 mb-5">
            The agent reads a few pages, writes a draft, then stops and waits for you
            before publishing. Close the tab while it works. Nothing is lost.
        </p>

        <form method="POST" action="{{ route('demo.start') }}" class="flex gap-3">
            @csrf
            <input name="topic" required
                   placeholder="How Postgres handles row-level locking"
                   class="flex-1 rounded-lg bg-slate-900 border border-slate-700 px-4 py-2.5 text-sm text-slate-100 placeholder-slate-600 focus:outline-none focus:border-teal-400">
            <button class="rounded-lg bg-teal-400 text-slate-900 px-5 py-2.5 text-sm font-semibold hover:bg-teal-300">
                Start
            </button>
        </form>
        @error('topic') <p class="text-rose-400 text-sm mt-2">{{ $message }}</p> @enderror
    </div>

    <h2 class="text-sm uppercase tracking-wider text-slate-500 mb-3">Sessions</h2>

    @forelse ($sessions as $session)
        @php $latest = $session->runs()->first(); @endphp
        <a href="{{ $latest ? route('demo.run', $latest->id) : '#' }}"
           class="panel rounded-lg px-4 py-3 mb-2 flex items-center justify-between hover:border-teal-500 transition">
            <div>
                <div class="text-slate-200 text-sm">{{ $session->name }}</div>
                <div class="text-xs text-slate-500 mt-0.5">
                    {{ $session->id }} · {{ $session->runs()->count() }} run(s) · {{ $session->created_at->diffForHumans() }}
                </div>
            </div>
            <span class="text-xs px-2 py-1 rounded
                {{ $latest?->status->value === 'completed' ? 'bg-teal-400/10 text-teal-300' : '' }}
                {{ in_array($latest?->status->value, ['awaiting_approval', 'suspended']) ? 'bg-amber-400/10 text-amber-300' : '' }}
                {{ in_array($latest?->status->value, ['failed', 'cancelled', 'budget_exceeded']) ? 'bg-rose-400/10 text-rose-300' : '' }}
                {{ in_array($latest?->status->value, ['queued', 'running', 'created']) ? 'bg-sky-400/10 text-sky-300' : '' }}">
                {{ $latest?->status->value ?? 'no runs' }}
            </span>
        </a>
    @empty
        <p class="text-sm text-slate-500">Nothing yet. Start a run above.</p>
    @endforelse
@endsection
