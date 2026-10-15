import type { GitHubLink } from '@/types';

export function LinkedText({
    text,
    links,
}: {
    text: string;
    links: GitHubLink[];
}) {
    if (links.length === 0) {
        return text;
    }

    const pattern = new RegExp(
        `(${links
            .map((link) => RegExp.escape(link.text))
            .toSorted((a, b) => b.length - a.length)
            .join('|')})`,
    );

    return text.split(pattern).map((part, index) => {
        const link = links.find((candidate) => candidate.text === part);

        return link ? (
            <a
                key={index}
                href={link.url}
                title={link.url}
                target="_blank"
                rel="noreferrer"
                className="font-medium text-primary hover:underline"
            >
                {link.label}
            </a>
        ) : (
            part
        );
    });
}
