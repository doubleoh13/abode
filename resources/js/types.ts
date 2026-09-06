export type AccountType = 'asset' | 'liability' | 'income' | 'expense' | 'equity';

export interface Institution {
    id: number;
    name: string;
}

export interface Account {
    id: number;
    account_type: AccountType;
    name: string;
    path: string;
    parent_id: number | null;
    institution?: Institution | null;
    opened_at: string | null;
    closed_at: string | null;
}
