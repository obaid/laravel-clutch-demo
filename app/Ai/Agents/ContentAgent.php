<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\FetchUrl;
use App\Ai\Tools\PublishPost;
use App\Ai\Tools\SaveDraft;
use Clutch\Laravel\Facades\Clutch;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Researches a topic, drafts a post, and asks before publishing it.
 *
 * An ordinary Laravel AI agent. The only thing Clutch asks of it is the
 * RemembersConversations trait, which is what lets a session continue across
 * requests, workers, and deploys.
 */
#[Provider('anthropic')]
#[Model('claude-sonnet-5')]
class ContentAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You research a topic and write a short, specific blog post about it.

        Work in this order:

        1. Read two or three relevant pages with fetch_url to ground yourself in
           real detail. Prefer primary sources over summaries.
        2. Write the post. Around 400 words, concrete, no filler, and no
           throat-clearing introduction. Cite the URLs you actually used.
        3. Save it with save_draft.
        4. Publish it with publish_post.

        Publishing is irreversible and a human has to approve it, so make sure
        the draft is genuinely finished before you reach for that step. If the
        publish is rejected, read the reason, fix what it points at, save the
        corrected draft, and stop there rather than trying to publish again.
        PROMPT;
    }

    public function tools(): iterable
    {
        // Passing the list through Clutch applies the session's permission
        // mode: it withholds anything denied and marks what needs sign-off.
        return Clutch::policy([
            new FetchUrl,
            new SaveDraft,
            new PublishPost,
        ]);
    }
}
