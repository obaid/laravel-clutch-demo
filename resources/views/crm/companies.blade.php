@include('crm._head', ['title' => 'Companies', 'meta' => $companies->count().' total'])

<table class="w-full text-sm">
    <thead>
        <tr class="text-left text-[11px] uppercase tracking-wide ash border-b hair" style="background:var(--soft)">
            <th class="px-6 py-2 font-semibold">Company</th>
            <th class="px-3 py-2 font-semibold">Industry</th>
            <th class="px-3 py-2 font-semibold text-right">Staff</th>
            <th class="px-3 py-2 font-semibold text-right">Contacts</th>
            <th class="px-6 py-2 font-semibold text-right">Deals</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($companies as $c)
            <tr class="border-b hair hover:bg-[var(--soft)]">
                <td class="px-6 py-2.5">
                    <span class="ink font-medium">{{ $c->name }}</span>
                    <div class="mono text-[11px] ash">{{ $c->domain }}</div>
                </td>
                <td class="px-3 py-2.5 mute">{{ $c->industry }}</td>
                <td class="px-3 py-2.5 text-right mute">{{ number_format($c->employees) }}</td>
                <td class="px-3 py-2.5 text-right mute">{{ $c->contacts_count }}</td>
                <td class="px-6 py-2.5 text-right mute">{{ $c->deals_count }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
