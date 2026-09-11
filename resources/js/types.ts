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
    display_precision: number;
    symbol: string | null;
    symbol_placement: SymbolPlacement | null;
    price_source: PriceSource | null;
    price_symbol: string | null;
    latest_price?: string;
    latest_priced_at?: string;
}

export type PriceSource = 'yahoo' | 'in529' | 'manual';

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
    allow_postings: boolean;
    institution?: Institution | null;
    simplefin_account_id: string | null;
    simplefin_synced_at: string | null;
    simplefin_balance: string | null;
    simplefin_balance_date: string | null;
    opened_at: string | null;
    closed_at: string | null;
}

export interface BankTransaction {
    id: number;
    financial_account_id: number;
    source: string;
    external_id: string;
    posted_on: string;
    transacted_on: string | null;
    pending: boolean;
    amount: string;
    currency: string;
    description: string | null;
    payee: string | null;
    memo: string | null;
    financial_posting_id: number | null;
    rejected_posting_ids: number[];
    candidate_posting_id: number | null;
}

export type PostingStatus = 'pending' | 'cleared' | 'reconciled';

export interface Lot {
    id: number;
    financial_commodity_id: number;
    commodity?: Commodity;
    acquired_at: string;
    cost: string;
    metadata: Record<string, unknown>;
    open_quantity?: string;
    acquired_quantity?: string;
}

export interface Posting {
    id: number;
    position: number;
    status: PostingStatus | null;
    financial_account_id: number;
    account?: Account;
    financial_commodity_id: number;
    commodity?: Commodity;
    financial_lot_id: number | null;
    lot?: Lot | null;
    amount: string;
    memo: string | null;
    metadata: Record<string, unknown>;
    transaction?: Transaction;
    bank_transaction?: BankTransaction | null;
    running_balance?: string;
}

export interface AccountBalance {
    financial_commodity_id: number;
    balance: string;
}

export interface CommodityBalance {
    financial_account_id: number;
    balance: string;
}

export interface CommodityPrice {
    id: number;
    financial_commodity_id: number;
    priced_at: string;
    price: string;
}

export interface Transaction {
    id: number;
    date: string;
    financial_payee_id: number | null;
    payee?: Payee | null;
    financial_recurring_transaction_id: number | null;
    memo: string | null;
    metadata: Record<string, unknown>;
    status?: PostingStatus | null;
    postings?: Posting[];
}

export type RecurrenceFrequency = 'daily' | 'weekly' | 'monthly' | 'yearly';

export interface RecurringPosting {
    id: number;
    position: number;
    status: PostingStatus | null;
    financial_account_id: number;
    account?: Account;
    financial_commodity_id: number;
    commodity?: Commodity;
    amount: string;
    memo: string | null;
    metadata: Record<string, unknown>;
}

export interface RecurringTransaction {
    id: number;
    financial_payee_id: number | null;
    payee?: Payee | null;
    memo: string | null;
    metadata: Record<string, unknown>;
    frequency: RecurrenceFrequency;
    interval: number;
    starts_on: string;
    next_due_on: string;
    ends_on: string | null;
    lead_days: number | null;
    postings?: RecurringPosting[];
}

export interface SimpleFinAccount {
    id: string;
    organization: string;
    name: string;
    currency: string;
    balance: string;
    balance_date: string;
}

export interface JournalIssue {
    type: 'negative_lot' | 'unbalanced_transaction' | 'failed_assertion';
    financial_transaction_id?: number;
    financial_lot_id?: number;
    financial_posting_id?: number;
    residual?: string;
    financial_balance_assertion_id?: number;
    financial_account_id?: number;
    financial_commodity_id?: number;
    asserted_at?: string;
    expected?: string;
    actual?: string;
    message: string;
}

export interface BalanceAssertion {
    id: number;
    financial_account_id: number;
    financial_commodity_id: number;
    commodity?: Commodity;
    asserted_at: string;
    balance: string;
    memo: string | null;
}

export interface BalanceSheetRow {
    financial_account_id: number;
    financial_commodity_id: number;
    account_type: AccountType;
    quantity: string;
    price: string | null;
    market_value: string | null;
    cost_basis: string | null;
}

export interface BalanceSheet {
    as_of: string;
    rows: BalanceSheetRow[];
    totals: {
        assets: string;
        liabilities: string;
        net_worth: string;
    };
}

export interface PostingDraft {
    id: number | null;
    status: PostingStatus | null;
    financial_account_id: number | null;
    financial_commodity_id: number | null;
    amount: string;
    memo: string;
    financial_lot_id: number | null;
    financial_bank_transaction_id: number | null;
    bankTransaction: BankTransaction | null;
    lotMode: 'existing' | 'new';
    lotCost: string;
    lotCostMode: 'total' | 'unit';
    lotAcquiredAt: string;
}

export interface ApiToken {
    id: number;
    name: string;
    last_used_at: string | null;
    created_at: string;
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
