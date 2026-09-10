import type { PostingStatus, RecurrenceFrequency } from './types';

export function statusSymbol(status: PostingStatus | null): string {
    if (status === 'pending') {
        return 'P';
    }

    if (status === 'cleared') {
        return 'C';
    }

    if (status === 'reconciled') {
        return 'R';
    }

    return ' ';
}

export function statusLabel(status: PostingStatus | null): string {
    return status === null ? 'No status' : status[0].toUpperCase() + status.slice(1);
}

export function statusClass(status: PostingStatus | null): string {
    return status === 'reconciled' ? 'text-accent' : 'text-muted';
}

export function nextStatus(status: PostingStatus): PostingStatus {
    if (status === 'pending') {
        return 'cleared';
    }

    if (status === 'cleared') {
        return 'reconciled';
    }

    return 'pending';
}

export function accountPathAncestor(path: string | undefined): string {
    const segments = path?.split(':') ?? [];

    return segments.length > 1 ? `${segments.slice(0, -1).join(':')}:` : '';
}

export function accountPathLeaf(path: string | undefined): string {
    return path?.split(':').at(-1) ?? '';
}

export function recurrenceLabel(frequency: RecurrenceFrequency, interval: number): string {
    const unit = { daily: 'day', weekly: 'week', monthly: 'month', yearly: 'year' }[frequency];

    return interval === 1 ? `Every ${unit}` : `Every ${interval} ${unit}s`;
}
