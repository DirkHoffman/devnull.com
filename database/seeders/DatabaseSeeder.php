<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Commit;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::factory()->count(10)->create();

        $users->each(function (User $user) use ($users): void {
            Commit::factory()->for($user)->count(fake()->numberBetween(1, 10))->sequence(
                fn (): array => ['created_at' => fake()->dateTimeBetween('-1 month')],
            )->create();

            $user->following()->attach(
                $users->except([$user->id])->random(fake()->numberBetween(1, 5))->modelKeys(),
            );
        });

        $this->seedGitHubReferences($users);
    }

    /**
     * Seed commits that reference real GitHub repositories, issues, pull requests, releases and commits.
     *
     * @param  Collection<int, User>  $users
     */
    private function seedGitHubReferences(Collection $users): void
    {
        collect([
            'Shipped the commit graph: nunomaduro/devnull.com#14',
            'Up for grabs: nunomaduro/devnull.com#21. Anyone fancy building the follower page?',
            'Reviewing nunomaduro/devnull.com#18 tonight. Counting Unicode characters is harder than it looks.',
            'Closed in favour of a simpler approach: https://github.com/nunomaduro/devnull.com/pull/10',
            'Auto-login landed, closing nunomaduro/devnull.com#12',
            'Laravel 13 is out. Release notes: https://github.com/laravel/framework/releases/tag/v13.0.0',
            'Highlighting code without any JavaScript: https://github.com/tempestphp/highlight',
            'The one-liner that saved my afternoon: nunomaduro/devnull.com@42faad4',
            'Pest 5 and Laravel 13, a good week: https://github.com/pestphp/pest/releases/tag/v5.0.0 and https://github.com/laravel/framework',
            'Typo in this one: acme/does-not-exist#1. That repository does not exist, so there is no card.',
            'Tabs and/or spaces? Neither, run Pint',
        ])->each(fn (string $body, int $index): Commit => Commit::factory()->for($users->random())->create([
            'body' => $body,
            'created_at' => now()->subMinutes($index),
        ]));
    }
}
