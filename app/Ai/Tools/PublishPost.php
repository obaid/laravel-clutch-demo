<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Post;
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
 * Publishes the post. The one step in this demo you cannot take back.
 *
 * Three separate protections meet on this class, and each guards a different
 * failure:
 *
 *   Approvable       a human decides, and the run waits however long that takes
 *   SensitiveTool    the policy engine knows this is irreversible
 *   IdempotentTool   a retry cannot publish twice, even after a crash
 *
 * The counter on the post is what makes the last one visible. Kill the worker
 * right after this runs and watch the retry leave it at one.
 */
class PublishPost implements Approvable, IdempotentTool, SensitiveTool, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Publish the saved draft to the public site. This cannot be undone.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->integer()->description('The post to publish.')->required(),
        ];
    }

    public function sensitivity(): ToolSensitivity
    {
        return ToolSensitivity::Irreversible;
    }

    /**
     * Key the side effect, not the call.
     *
     * Two retries of "publish post 7" have to collide even though their
     * tool-call IDs differ, which is exactly what happens when a worker dies
     * after the publish but before the result is recorded.
     */
    public function idempotencyKey(ToolInvocation $invocation): string
    {
        return 'publish-post:'.($invocation->arguments['post_id'] ?? 'unknown');
    }

    public function handle(Request $request): Stringable|string
    {
        $post = Post::query()->findOrFail((int) $request['post_id']);

        // Counts every time the tool body actually executes, which is what the
        // demo asserts against. The ledger should keep this at one.
        $post->increment('publish_attempts');

        if ($post->isPublished()) {
            return "Post {$post->id} was already published at {$post->published_at}.";
        }

        $post->publish();

        return "Published \"{$post->title}\".";
    }
}
