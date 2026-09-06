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
