export type GitHubLink = {
    text: string;
    url: string;
    label: string;
};

export type GitHubCard = {
    type: 'repository' | 'issue' | 'pull_request' | 'release' | 'commit';
    url: string;
    label: string;
    title: string | null;
    state: 'open' | 'closed' | 'merged' | 'released' | null;
    stars: number | null;
    language: string | null;
};
