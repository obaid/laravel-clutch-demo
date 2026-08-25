<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\ApplyDiscount;
use App\Ai\Tools\EmailContact;
use App\Ai\Tools\GetDeal;
use App\Ai\Tools\ListDeals;
use App\Ai\Tools\LogNote;
use App\Ai\Tools\MoveDealStage;
use App\Ai\Tools\SearchCrm;
use Clutch\Laravel\Facades\Clutch;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A sales assistant that can actually change the pipeline.
 *
 * An ordinary Laravel AI agent. The only thing Clutch asks of it is the
 * RemembersConversations trait, which is what lets one thread continue across
 * requests, workers and deploys.
 */
class CrmAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function provider(): ?string
    {
        return config('ai.default');
    }

    public function model(): ?string
    {
        return env('AI_MODEL') ?: null;
    }

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a sales assistant working inside a CRM alongside the rep who
        owns the pipeline.

        Look things up before you act. If someone names a company, a person or a
        deal, search for it and read the record rather than assuming. Never
        invent a reference, a value, a stage or a contact you have not read.

        You can log notes and move deals between stages freely.

        Emailing a contact and discounting a deal both need a human to approve
        them, and this is the part to get right: approval is handled for you.
        When you call one of those tools, the rep is shown exactly what you
        passed and decides then and there.

        So call the tool. Do not describe what you would send and wait to be
        told to go ahead, because nobody is going to answer that: the approval
        prompt only appears once you actually make the call. Write the real
        subject and body, or the real percentage, and call it.

        A short line of context first is welcome. A request for permission is
        not, because making the call is how you ask.

        If an approval is rejected, read the reason, say what you will do
        instead, and do not simply try the same thing again.

        Keep replies short. You are talking to a colleague looking at the same
        screen, so refer to deals by reference and skip the preamble.
        PROMPT;
    }

    public function tools(): iterable
    {
        // Passing the list through Clutch applies the session's permission
        // mode: it withholds anything denied and marks what needs sign-off.
        return Clutch::policy([
            new SearchCrm,
            new ListDeals,
            new GetDeal,
            new LogNote,
            new MoveDealStage,
            new EmailContact,
            new ApplyDiscount,
        ]);
    }
}
