@extends('layout')
@section('title', 'Approvals · Laravel Clutch demo')

@section('content')
    <h1 class="text-lg font-semibold text-slate-100 mb-1">Waiting on a human</h1>
    <p class="text-sm text-slate-400 mb-6">
        These runs are parked. No worker is holding a connection open for them, and
        nothing here knows which process started them. Deploy in between if you like.
    </p>

    @forelse ($approvals as $approval)
        <div class="panel rounded-xl p-5 mb-3">
            <div class="flex items-start justify-between mb-2">
                <div>
                    <code class="accent text-sm">{{ $approval->tool_name }}</code>
                    <div class="text-xs text-slate-500 mt-1">
                        asked {{ $approval->requested_at->diffForHumans() }} · run {{ $approval->run_id }}
                    </div>
                </div>
                <a href="{{ route('demo.run', $approval->run_id) }}" class="text-xs text-slate-500 hover:text-teal-300">view run</a>
            </div>

            <p class="text-xs text-slate-400 mb-3">{{ $approval->reason }}</p>
            <pre class="text-xs bg-slate-900 rounded p-3 mb-4 overflow-x-auto text-slate-300">{{ json_encode($approval->arguments, JSON_PRETTY_PRINT) }}</pre>

            <form method="POST" action="{{ route('demo.decide', $approval->id) }}" class="flex gap-2">
                @csrf
                <input name="reason" placeholder="Why? (optional)"
                       class="flex-1 rounded-lg bg-slate-900 border border-slate-700 px-3 py-2 text-sm">
                <button name="decision" value="approve"
                        class="rounded-lg bg-teal-400 text-slate-900 px-4 py-2 text-sm font-semibold hover:bg-teal-300">Approve</button>
                <button name="decision" value="reject"
                        class="rounded-lg border border-slate-600 px-4 py-2 text-sm hover:border-rose-400 hover:text-rose-300">Reject</button>
            </form>
        </div>
    @empty
        <p class="text-sm text-slate-500">Nothing waiting.</p>
    @endforelse
@endsection
