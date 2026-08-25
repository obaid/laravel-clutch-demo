<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Laravel Clutch demo')</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { background: #0b0e14; color: #b3bccd; font-family: ui-sans-serif, system-ui, sans-serif; }
        .panel { background: #11151f; border: 1px solid #1e2534; }
        .accent { color: #3ddbc0; }
        code, pre { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
</head>
<body class="min-h-screen">
<div class="max-w-5xl mx-auto px-6 py-8">
    <header class="flex items-center justify-between mb-8">
        <a href="{{ route('demo.index') }}" class="text-xl font-semibold text-slate-100">
            Laravel <span class="accent">Clutch</span>
            <span class="text-slate-500 text-sm font-normal ml-2">demo</span>
        </a>
        <nav class="flex items-center gap-5 text-sm">
            <a href="{{ route('demo.index') }}" class="hover:text-teal-300">Runs</a>
            <a href="{{ route('demo.approvals') }}" class="hover:text-teal-300">
                Approvals
                @if ($count = \Clutch\Laravel\Models\Approval::query()->pending()->count())
                    <span class="ml-1 px-2 py-0.5 rounded-full bg-teal-400 text-slate-900 text-xs font-semibold">{{ $count }}</span>
                @endif
            </a>
            <a href="https://github.com/obaid/laravel-clutch" class="text-slate-500 hover:text-teal-300">GitHub</a>
        </nav>
    </header>

    @if (session('chaos'))
        <div class="panel rounded-lg px-4 py-3 mb-6 border-l-2 border-l-amber-400 text-sm">
            {{ session('chaos') }}
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
