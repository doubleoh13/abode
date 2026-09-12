import type { PostingStatus, RecurrenceFrequency } from './types';

export function statusLabel(status: PostingStatus | null): string {
    return status === null ? 'No status' : status[0].toUpperCase() + status.slice(1);
}

export function statusClass(matched: boolean): string {
    return matched ? 'text-accent' : 'text-muted';
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

export function isoDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

export function localToday(): string {
    return isoDate(new Date());
}

export function localTomorrow(): string {
    const now = new Date();

    return isoDate(new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1));
}
