<?php

declare(strict_types=1);

use App\Ai\Agents\CrmAgent;

it('follows the configured provider', function (string $provider): void {
    config()->set('ai.default', $provider);

    expect((new CrmAgent)->provider())->toBe($provider);
})->with(['openai', 'anthropic']);

it('lets the provider choose its own model when none is pinned', function (): void {
    expect((new CrmAgent)->model())->toBeNull();
});

it('follows whichever provider is configured', function (): void {
    foreach (['openai', 'anthropic'] as $provider) {
        config()->set('ai.default', $provider);

        expect((new CrmAgent)->provider())->toBe($provider);
    }
});
