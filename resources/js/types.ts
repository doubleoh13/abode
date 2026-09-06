declare global {
    interface Window {
        developmentLoginEnabled?: boolean;
    }
}

export type AccountType = 'asset' | 'liability' | 'income' | 'expense' | 'equity';

export type CommodityKind = 'currency' | 'traded' | 'custom';

export type SymbolPlacement = 'prefix' | 'suffix';

export interface Commodity {
    id: number;
    code: string;
    name: string;
    kind: CommodityKind;
    precision: number;
    symbol: string | null;
    symbol_placement: SymbolPlacement | null;
}

export interface Institution {
    id: number;
    name: string;
}

export interface Payee {
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

export type PostingStatus = 'pending' | 'cleared' | 'reconciled';

export interface Lot {
    id: number;
    financial_commodity_id: number;
    commodity?: Commodity;
    acquired_at: string;
    cost: number;
    metadata: Record<string, unknown>;
    open_quantity?: number;
    acquired_quantity?: number;
}

export interface Posting {
    id: number;
    position: number;
    status: PostingStatus;
    financial_account_id: number;
    account?: Account;
    financial_commodity_id: number;
    commodity?: Commodity;
    financial_lot_id: number | null;
    lot?: Lot | null;
    amount: number;
    memo: string | null;
    metadata: Record<string, unknown>;
}

export interface Transaction {
    id: number;
    date: string;
    financial_payee_id: number | null;
    payee?: Payee | null;
    memo: string | null;
    metadata: Record<string, unknown>;
    status: PostingStatus;
    postings?: Posting[];
}

export interface JournalIssue {
    type: 'negative_lot' | 'unbalanced_transaction';
    financial_transaction_id: number;
    financial_lot_id?: number;
    financial_posting_id?: number;
    residual?: number;
    message: string;
}

export interface PostingDraft {
    id: number | null;
    status: PostingStatus;
    financial_account_id: number | null;
    financial_commodity_id: number | null;
    amount: string;
    memo: string;
    financial_lot_id: number | null;
    lotMode: 'existing' | 'new';
    lotCost: string;
    lotCostMode: 'total' | 'unit';
    lotAcquiredAt: string;
}

export interface Paginated<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
    };
}
