<?php

declare(strict_types=1);

use App\Actions\AttachGitHubContext;
use App\Models\Commit;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('attaches the links of a commit and a card for the first one', function (): void {
    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => Http::response(['title' => 'Add follower page', 'state' => 'open'])]);

    Commit::factory()->create(['body' => 'Merged laravel/framework#5123, see https://github.com/laravel/framework']);

    $commits = resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    expect($commits->items()[0])
        ->github_links->toBe([
            ['text' => 'laravel/framework#5123', 'url' => 'https://github.com/laravel/framework/issues/5123', 'label' => 'laravel/framework#5123'],
            ['text' => 'https://github.com/laravel/framework', 'url' => 'https://github.com/laravel/framework', 'label' => 'laravel/framework'],
        ])
        ->github_card->toBe([
            'type' => 'issue',
            'url' => 'https://github.com/laravel/framework/issues/5123',
            'label' => 'laravel/framework#5123',
            'title' => 'Add follower page',
            'state' => 'open',
            'stars' => null,
            'language' => null,
        ]);
});

it('describes each kind of reference', function (string $body, string $endpoint, array $response, array $card): void {
    Http::fake([$endpoint => Http::response($response)]);

    Commit::factory()->create(['body' => $body]);

    $commits = resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    expect($commits->items()[0]['github_card'])->toMatchArray($card);
})->with([
    'repository' => [
        'https://github.com/laravel/framework',
        'api.github.com/repos/laravel/framework',
        ['description' => 'The Laravel Framework.', 'stargazers_count' => 34120, 'language' => 'PHP'],
        ['type' => 'repository', 'title' => 'The Laravel Framework.', 'stars' => 34120, 'language' => 'PHP'],
    ],
    'merged pull request' => [
        'laravel/framework#5124',
        'api.github.com/repos/laravel/framework/issues/5124',
        ['title' => 'Fix eager loading', 'state' => 'closed', 'pull_request' => ['merged_at' => '2026-10-01T12:00:00Z']],
        ['type' => 'pull_request', 'title' => 'Fix eager loading', 'state' => 'merged'],
    ],
    'closed pull request' => [
        'laravel/framework#5125',
        'api.github.com/repos/laravel/framework/issues/5125',
        ['title' => 'Rewrite in Rust', 'state' => 'closed', 'pull_request' => ['merged_at' => null]],
        ['type' => 'pull_request', 'title' => 'Rewrite in Rust', 'state' => 'closed'],
    ],
    'release' => [
        'https://github.com/laravel/framework/releases/tag/v13.0.0',
        'api.github.com/repos/laravel/framework/releases/tags/v13.0.0',
        ['name' => 'v13.0.0'],
        ['type' => 'release', 'title' => 'v13.0.0', 'state' => 'released'],
    ],
    'commit' => [
        'laravel/framework@42faad4',
        'api.github.com/repos/laravel/framework/commits/42faad4',
        ['commit' => ['message' => "feat: add opt-in local auto-login\n\nSigns in as a local user."]],
        ['type' => 'commit', 'title' => 'feat: add opt-in local auto-login'],
    ],
]);

it('falls back to a card without details when github is unavailable', function (PromiseInterface|Closure $response): void {
    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => $response]);

    Commit::factory()->create(['body' => 'Merged laravel/framework#5123']);

    $commits = resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    expect($commits->items()[0]['github_card'])->toMatchArray([
        'label' => 'laravel/framework#5123',
        'title' => null,
        'state' => null,
    ]);
})->with([
    'server error' => fn (): PromiseInterface => Http::response(status: 500),
    'connection failure' => fn (): Closure => Http::failedConnection(),
]);

it('caches cards for ten minutes', function (): void {
    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => Http::response(['title' => 'Add follower page', 'state' => 'open'])]);

    Commit::factory()->create(['body' => 'Merged laravel/framework#5123']);

    $action = resolve(AttachGitHubContext::class);

    $action->handle(Commit::query()->cursorPaginate());
    $this->travel(9)->minutes();
    $action->handle(Commit::query()->cursorPaginate());

    Http::assertSentCount(1);

    $this->travel(2)->minutes();
    $action->handle(Commit::query()->cursorPaginate());

    Http::assertSentCount(2);
});

it('retries unavailable cards after a minute', function (): void {
    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => Http::response(status: 500)]);

    Commit::factory()->create(['body' => 'Merged laravel/framework#5123']);

    $action = resolve(AttachGitHubContext::class);

    $action->handle(Commit::query()->cursorPaginate());
    $this->travel(2)->minutes();
    $action->handle(Commit::query()->cursorPaginate());

    Http::assertSentCount(2);
});

it('does not show a card for references that do not exist', function (): void {
    Http::fake(['api.github.com/repos/acme/does-not-exist/issues/1' => Http::response(status: 404)]);

    Commit::factory()->create(['body' => 'Typo in acme/does-not-exist#1']);

    $action = resolve(AttachGitHubContext::class);

    $commits = $action->handle(Commit::query()->cursorPaginate());
    $this->travel(2)->minutes();
    $action->handle(Commit::query()->cursorPaginate());

    expect($commits->items()[0])
        ->github_links->toHaveCount(1)
        ->github_card->toBeNull();

    Http::assertSentCount(1);
});

it('requests a reference shared by commits once', function (): void {
    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => Http::response(['title' => 'Add follower page', 'state' => 'open'])]);

    Commit::factory()->count(2)->create(['body' => 'Merged laravel/framework#5123']);

    $commits = resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    expect($commits->items())->each->toHaveKey('github_card.title', 'Add follower page');

    Http::assertSentCount(1);
});

it('authenticates with the configured token', function (): void {
    config(['services.github.token' => 'secret']);

    Http::fake(['api.github.com/repos/laravel/framework/issues/5123' => Http::response(['title' => 'Add follower page', 'state' => 'open'])]);

    Commit::factory()->create(['body' => 'Merged laravel/framework#5123']);

    resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
});

it('does not call github for commits without references', function (): void {
    Commit::factory()->create(['body' => 'Fixed it, and/or broke it']);

    $commits = resolve(AttachGitHubContext::class)->handle(Commit::query()->cursorPaginate());

    expect($commits->items()[0])
        ->github_links->toBe([])
        ->github_card->toBeNull();

    Http::assertNothingSent();
});
