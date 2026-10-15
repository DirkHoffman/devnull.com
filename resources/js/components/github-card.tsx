import {
    BookMarked,
    CircleDot,
    GitCommitHorizontal,
    GitMerge,
    GitPullRequest,
    Star,
    Tag,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import type { GitHubCard as Card } from '@/types';

const icons = {
    repository: BookMarked,
    issue: CircleDot,
    pull_request: GitPullRequest,
    release: Tag,
    commit: GitCommitHorizontal,
};

const compact = new Intl.NumberFormat('en', { notation: 'compact' });

export function GitHubCard({ card }: { card: Card }) {
    const Icon = card.state === 'merged' ? GitMerge : icons[card.type];

    return (
        <a
            href={card.url}
            target="_blank"
            rel="noreferrer"
            className="mt-2 flex items-center gap-3 rounded-md border px-3 py-2 transition-colors hover:bg-muted/60"
            data-test="github-card"
        >
            <Icon
                className={cn('size-4 shrink-0 text-muted-foreground', {
                    'text-(--lane-1)': card.state === 'open',
                    'text-(--lane-2)': card.state === 'merged',
                })}
            />

            <span className="min-w-0 flex-1">
                <span className="block truncate font-mono text-[13px] text-muted-foreground">
                    {card.label}
                </span>
                {card.title && (
                    <span className="block truncate text-sm font-medium">
                        {card.title}
                    </span>
                )}
            </span>

            {card.stars !== null && (
                <span className="flex shrink-0 items-center gap-1 font-mono text-[13px] text-muted-foreground">
                    <Star className="size-3.5" />
                    {compact.format(card.stars)}
                    {card.language && ` · ${card.language}`}
                </span>
            )}

            {card.state && (
                <span className="shrink-0 font-mono text-[13px] text-muted-foreground">
                    {card.state}
                </span>
            )}
        </a>
    );
}
