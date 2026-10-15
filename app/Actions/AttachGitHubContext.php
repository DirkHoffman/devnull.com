<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GitHubReferenceType;
use App\Models\Commit;
use App\Services\GitHubReference;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Fluent;

/**
 * @phpstan-type Card array{type: string, url: string, label: string, title: string|null, state: string|null, stars: int|null, language: string|null}
 * @phpstan-type Details array{type?: string, title?: string|null, state?: string|null, stars?: int, language?: string|null}
 */
final readonly class AttachGitHubContext
{
    /**
     * Attach the GitHub links of each commit, and a card for the first one.
     *
     * @param  CursorPaginator<int, Commit>  $commits
     * @return CursorPaginator<int, array<string, mixed>>
     */
    public function handle(CursorPaginator $commits): CursorPaginator
    {
        $references = $commits->getCollection()->mapWithKeys(fn (Commit $commit): array => [
            $commit->id => GitHubReference::find($commit->body),
        ]);

        $cards = $this->cards($references->map(fn (array $found): ?GitHubReference => $found[0] ?? null)->filter());

        return $commits->through(fn (Commit $commit): array => [
            ...$commit->toArray(),
            'github_links' => array_map(fn (GitHubReference $reference): array => $reference->toArray(), $references->get($commit->id, [])),
            'github_card' => $cards->get($commit->id),
        ]);
    }

    /**
     * Fetch the uncached references concurrently, then cache what GitHub returned for ten minutes.
     * When GitHub is unavailable, the card has no details and is retried after a minute.
     *
     * @param  Collection<string, GitHubReference>  $references
     * @return Collection<string, Card|null>
     */
    private function cards(Collection $references): Collection
    {
        $uncached = $references
            ->unique(fn (GitHubReference $reference): string => $reference->apiPath())
            ->reject(fn (GitHubReference $reference): bool => Cache::has($this->cacheKey($reference)));

        $responses = Http::pool(function (Pool $pool) use ($uncached): void {
            foreach ($uncached as $reference) {
                $this->github($pool->as($reference->apiPath()))->get($reference->apiPath());
            }
        });

        return $references->map(function (GitHubReference $reference) use ($responses): ?array {
            $details = Cache::remember(
                $this->cacheKey($reference),
                fn (array|false $details): CarbonInterface => $details === [] ? now()->addMinute() : now()->addMinutes(10),
                fn (): array|false => $this->details($reference, $responses[$reference->apiPath()] ?? null),
            );

            if ($details === false) {
                return null;
            }

            return [
                'type' => $reference->type->value,
                'url' => $reference->url(),
                'label' => $reference->label(),
                'title' => null,
                'state' => null,
                'stars' => null,
                'language' => null,
                ...$details,
            ];
        });
    }

    /**
     * Describe the reference from GitHub's response: false when it does not exist, empty when GitHub is unavailable.
     *
     * @return Details|false
     */
    private function details(GitHubReference $reference, mixed $response): array|false
    {
        if ($response instanceof Response && $response->notFound()) {
            return false;
        }

        if (! $response instanceof Response || ! $response->successful()) {
            return [];
        }

        $data = $response->fluent();

        return match ($reference->type) {
            GitHubReferenceType::Repository => [
                'title' => $this->firstLine($data, 'description'),
                'stars' => $data->integer('stargazers_count'),
                'language' => $this->firstLine($data, 'language'),
            ],
            GitHubReferenceType::Issue, GitHubReferenceType::PullRequest => [
                'type' => ($data->has('pull_request') ? GitHubReferenceType::PullRequest : GitHubReferenceType::Issue)->value,
                'title' => $this->firstLine($data, 'title'),
                'state' => $data->filled('pull_request.merged_at') ? 'merged' : $this->firstLine($data, 'state'),
            ],
            GitHubReferenceType::Release => [
                'title' => $this->firstLine($data, 'name'),
                'state' => 'released',
            ],
            GitHubReferenceType::Commit => [
                'title' => $this->firstLine($data, 'commit.message'),
            ],
        };
    }

    private function github(PendingRequest $request): PendingRequest
    {
        $token = config('services.github.token');

        if (is_string($token) && $token !== '') {
            $request->withToken($token);
        }

        return $request->baseUrl('https://api.github.com')
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->connectTimeout(2)
            ->timeout(3);
    }

    /**
     * @param  Fluent<string, mixed>  $data
     */
    private function firstLine(Fluent $data, string $key): ?string
    {
        $line = $data->string($key)->before("\n")->trim()->value();

        return $line === '' ? null : $line;
    }

    private function cacheKey(GitHubReference $reference): string
    {
        return 'github:'.hash('xxh128', $reference->apiPath());
    }
}
