<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use Clutch\Laravel\Contracts\IdempotentTool;
use Clutch\Laravel\Contracts\SensitiveTool;
use Clutch\Laravel\Data\ToolInvocation;
use Clutch\Laravel\Enums\ToolSensitivity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Emails a prospect. You cannot unsend it, so a human reads it first.
 *
 * Keyed on recipient plus subject, so a retry after a crash does not send a
 * duplicate while a genuinely different follow-up still goes out.
 */
class EmailContact implements Approvable, IdempotentTool, SensitiveTool, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Email a contact. They will actually receive this and it cannot be taken back.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_email' => $schema->string()->description('Who to email.')->required(),
            'subject' => $schema->string()->required(),
            'body' => $schema->string()->description('The message, in plain text.')->required(),
            'deal_reference' => $schema->string()->description('The deal this relates to, if any.'),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    public function idempotencyKey(ToolInvocation $invocation): string
    {
        return 'email:'.($invocation->arguments['contact_email'] ?? '?')
            .':'.md5((string) ($invocation->arguments['subject'] ?? ''));
    }

    public function handle(Request $request): Stringable|string
    {
        $contact = Contact::query()->where('email', (string) $request['contact_email'])->first();

        if (! $contact) {
            return "No contact with the email {$request['contact_email']}.";
        }

        $deal = Deal::query()->where('reference', (string) ($request['deal_reference'] ?? ''))->first();

        Activity::query()->create([
            'deal_id' => $deal?->id,
            'contact_id' => $contact->id,
            'kind' => 'email',
            'summary' => 'Sent: '.$request['subject'],
            'body' => (string) $request['body'],
            'by_agent' => true,
        ]);

        $deal?->forceFill(['last_touched_at' => now()])->save();

        return "Emailed {$contact->email}: \"{$request['subject']}\"";
    }
}
