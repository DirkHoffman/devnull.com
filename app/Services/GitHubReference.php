<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GitHubReferenceType;
use Illuminate\Support\Str;

final readonly class GitHubReference
{
    private const string URL = '~^https?://(?:www\.)?github\.com/';

    private const string REPOSITORY = '(?<owner>[a-z\d][a-z\d-]{0,38})/(?<repository>[\w.-]{1,100})';

    /**
     * The supported formats, each matched against a whole word.
     *
     * @var array<string, GitHubReferenceType>
     */
    private const array FORMATS = [
        self::URL.self::REPOSITORY.'/?$~i' => GitHubReferenceType::Repository,
        self::URL.self::REPOSITORY.'/issues/(?<id>\d+)$~i' => GitHubReferenceType::Issue,
        self::URL.self::REPOSITORY.'/pull/(?<id>\d+)$~i' => GitHubReferenceType::PullRequest,
        self::URL.self::REPOSITORY.'/releases/tag/(?<id>[^/]+)$~i' => GitHubReferenceType::Release,
        self::URL.self::REPOSITORY.'/commit/(?<id>[a-f\d]{7,40})$~i' => GitHubReferenceType::Commit,
        '~^'.self::REPOSITORY.'#(?<id>\d+)$~i' => GitHubReferenceType::Issue,
        '~^'.self::REPOSITORY.'@(?<id>[a-f\d]{7,40})$~i' => GitHubReferenceType::Commit,
    ];

    public function __construct(
        public GitHubReferenceType $type,
        public string $text,
        public string $owner,
        public string $repository,
        public ?string $id = null,
    ) {}

    /**
     * Find the GitHub references in the given text, in order of appearance.
     *
     * @return array<int, self>
     */
    public static function find(string $text): array
    {
        return Str::of($text)
            ->split('/\s+/')
            ->map(fn (string $word): ?self => self::parse(mb_trim($word, '()[]<>"\'`.,;:!?')))
            ->filter()
            ->unique(fn (self $reference): string => $reference->text)
            ->values()
            ->all();
    }

    public function url(): string
    {
        return "https://github.com/{$this->owner}/{$this->repository}".match ($this->type) {
            GitHubReferenceType::Repository => '',
            GitHubReferenceType::Issue => "/issues/{$this->id}",
            GitHubReferenceType::PullRequest => "/pull/{$this->id}",
            GitHubReferenceType::Release => "/releases/tag/{$this->id}",
            GitHubReferenceType::Commit => "/commit/{$this->id}",
        };
    }

    public function label(): string
    {
        return "{$this->owner}/{$this->repository}".match ($this->type) {
            GitHubReferenceType::Repository => '',
            GitHubReferenceType::Issue, GitHubReferenceType::PullRequest => "#{$this->id}",
            GitHubReferenceType::Release => "@{$this->id}",
            GitHubReferenceType::Commit => '@'.Str::substr((string) $this->id, 0, 7),
        };
    }

    public function apiPath(): string
    {
        return "repos/{$this->owner}/{$this->repository}".match ($this->type) {
            GitHubReferenceType::Repository => '',
            GitHubReferenceType::Issue, GitHubReferenceType::PullRequest => "/issues/{$this->id}",
            GitHubReferenceType::Release => "/releases/tags/{$this->id}",
            GitHubReferenceType::Commit => "/commits/{$this->id}",
        };
    }

    /**
     * @return array{text: string, url: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'url' => $this->url(),
            'label' => $this->label(),
        ];
    }

    private static function parse(string $word): ?self
    {
        foreach (self::FORMATS as $pattern => $type) {
            if (preg_match($pattern, $word, $matches) === 1) {
                return new self($type, $word, $matches['owner'], $matches['repository'], $matches['id'] ?? null);
            }
        }

        return null;
    }
}
