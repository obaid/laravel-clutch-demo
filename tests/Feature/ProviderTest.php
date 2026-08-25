<?php

declare(strict_types=1);

use App\Ai\Agents\ContentAgent;

it('follows the configured provider', function (string $provider): void {
    config()->set('ai.default', $provider);

    expect((new ContentAgent)->provider())->toBe($provider);
})->with(['openai', 'anthropic']);

it('lets the provider choose its own model when none is pinned', function (): void {
    expect((new ContentAgent)->model())->toBeNull();
});

it('reports which provider needs a key', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', null);

    $this->get('/')->assertOk()->assertSee('OPENAI_API_KEY');

    config()->set('ai.default', 'anthropic');
    config()->set('ai.providers.anthropic.key', null);

    $this->get('/')->assertOk()->assertSee('ANTHROPIC_API_KEY');
});

it('says which provider is answering once a key is set', function (): void {
    config()->set('ai.default', 'openai');
    config()->set('ai.providers.openai.key', 'sk-test');

    $this->get('/')->assertOk()->assertSee('Answering with');
});
