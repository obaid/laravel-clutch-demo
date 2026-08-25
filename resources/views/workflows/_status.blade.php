@php
    $map = [
        'completed' => ['Completed', 'var(--green)', 'var(--green-soft)'],
        'awaiting_approval' => ['Waiting for you', 'var(--primary-press)', '#fdf0d0'],
        'running' => ['Running', 'var(--blue)', 'var(--blue-soft)'],
        'queued' => ['Queued', 'var(--mute)', 'var(--soft)'],
        'created' => ['Created', 'var(--mute)', 'var(--soft)'],
        'failed' => ['Failed', 'var(--red, #c0392b)', '#f8dcd8'],
        'cancelled' => ['Cancelled', 'var(--mute)', 'var(--soft)'],
        'budget_exceeded' => ['Out of budget', 'var(--red, #c0392b)', '#f8dcd8'],
    ];
    [$label, $fg, $bg] = $map[$status] ?? [$status, 'var(--mute)', 'var(--soft)'];
@endphp
<span class="inline-block rounded px-1.5 py-0.5 text-[11px] font-semibold"
      style="color:{{ $fg }};background:{{ $bg }}">{{ $label }}</span>
