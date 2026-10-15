<?php

declare(strict_types=1);

use App\Services\GitHubReference;

it('finds a github reference', function (string $text, string $url, string $label): void {
    $references = GitHubReference::find($text);

    expect($references)->toHaveCount(1)
        ->and($references[0]->url())->toBe($url)
        ->and($references[0]->label())->toBe($label);
})->with([
    'repository' => ['https://github.com/laravel/framework', 'https://github.com/laravel/framework', 'laravel/framework'],
    'issue' => ['https://github.com/laravel/framework/issues/5123', 'https://github.com/laravel/framework/issues/5123', 'laravel/framework#5123'],
    'pull request' => ['https://github.com/laravel/framework/pull/5124', 'https://github.com/laravel/framework/pull/5124', 'laravel/framework#5124'],
    'release' => ['https://github.com/laravel/framework/releases/tag/v13.0.0', 'https://github.com/laravel/framework/releases/tag/v13.0.0', 'laravel/framework@v13.0.0'],
    'commit' => ['https://github.com/laravel/framework/commit/42faad4cfa50bcaa619b55ce5db7704ea673e2aa', 'https://github.com/laravel/framework/commit/42faad4cfa50bcaa619b55ce5db7704ea673e2aa', 'laravel/framework@42faad4'],
    'issue shorthand' => ['laravel/framework#5123', 'https://github.com/laravel/framework/issues/5123', 'laravel/framework#5123'],
    'commit shorthand' => ['laravel/framework@42faad4', 'https://github.com/laravel/framework/commit/42faad4', 'laravel/framework@42faad4'],
]);

it('ignores text that is not a github reference', function (string $text): void {
    expect(GitHubReference::find($text))->toBeEmpty();
})->with([
    'and/or',
    'laravel/framework',
    'laravel/framework#docs',
    'https://github.com/laravel',
    'https://github.com/laravel/framework/tree/13.x',
    'https://github.com.evil.test/laravel/framework',
    'https://gitlab.com/laravel/framework/-/issues/1',
]);

it('finds each reference once in order of appearance', function (): void {
    $references = GitHubReference::find('Shipped laravel/framework#2, then laravel/framework#1 and laravel/framework#2 again');

    expect(array_map(fn (GitHubReference $reference): string => $reference->text, $references))
        ->toBe(['laravel/framework#2', 'laravel/framework#1']);
});

it('keeps the text as written without surrounding punctuation', function (): void {
    $references = GitHubReference::find('Merged (https://github.com/laravel/framework/pull/5124)!');

    expect($references[0]->text)->toBe('https://github.com/laravel/framework/pull/5124');
});
