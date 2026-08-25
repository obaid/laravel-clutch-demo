<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchCrm implements SensitiveTool, Tool
{
    public function description(): Stringable|string
    {
        return 'Search the CRM across companies, contacts and deals.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('A name, email, domain or deal reference.')->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::ReadOnly;
    }

    public function handle(Request $request): Stringable|string
    {
        $q = (string) $request['query'];
        $out = [];

        foreach (Company::query()->where('name', 'like', "%{$q}%")->orWhere('domain', 'like', "%{$q}%")->limit(5)->get() as $c) {
            $out[] = "company  #{$c->id} {$c->name} ({$c->domain}) {$c->industry}, {$c->employees} staff";
        }

        foreach (Contact::query()->with('company')->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->limit(5)->get() as $c) {
            $out[] = "contact  #{$c->id} {$c->name} <{$c->email}> {$c->title} at {$c->company->name}";
        }

        foreach (Deal::query()->with('company')->where('name', 'like', "%{$q}%")->orWhere('reference', 'like', "%{$q}%")->limit(5)->get() as $d) {
            $out[] = "deal     {$d->reference} {$d->name} {$d->value()} {$d->stage} at {$d->company->name}";
        }

        return $out === [] ? "Nothing in the CRM matches \"{$q}\"." : implode("\n", $out);
    }
}
