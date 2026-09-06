---
paths:
  - 'app/Models/**'
---

# Models

## Account (chart of accounts) modeling decisions
accounts is a ledger-style chart of accounts: account_type enum (Asset/Liability/Income/Expense/Equity), self-referential nullable parent_id, nullable institution_id.
name stores the leaf segment only; the full path ("expenses:food:dining-out") is derived — the top-level prefix comes from account_type, never from a root row. Trees are small: load and build in memory, no path columns or nested sets.
Invariants (validation-layer, not DB): child account_type must equal parent's; no cycles; names unique among siblings (DB unique can't cover null-parent roots).
Lifecycle: opened_at/closed_at nullable DATEs. Both null = always open. closed_at set = hidden from current pickers, still in historical reports covering its window. opened_at set = excluded from reports dated before it.
Deliberately absent: currency (single-currency), any balance column (derive from future transactions).

## Commodity modeling decisions
commodities is one uniform table, hledger-style — a future posting is always (account, quantity, commodity) whether USD, SPAXX, FBTC, ETH, or HOUSE. Never split per asset class.
kind is BEHAVIORAL, not descriptive: Currency (unit of account), Traded (market-priced), Custom (manually valued, e.g. HOUSE). Resist adding descriptive cases that behave identically.
precision is display decimals per commodity and will inform amount storage when postings are designed (not designed yet).
symbol + symbol_placement (prefix/suffix) render "$1,234.56" vs "10.500 SPAXX"; both null = code-as-suffix. They are a pair: validation requires placement with symbol.
USD-as-base-currency is config-in-code when needed, never a flag column. Price history, lots/cost basis: deliberately undesigned.

## Amount storage: bigint minor units scaled by commodity precision
All journal/posting amounts are signed BIGINT in the commodity's minor units; commodity precision is the STORAGE SCALE, not just display ($12.34 = 1234 at precision 2). Balances are per-commodity integer SUMs — exact on Postgres and SQLite alike (never use decimal columns for amounts; SQLite makes them floats).
precision is effectively immutable once amounts reference the commodity — changing it requires an explicit rescaling migration; validation should refuse it otherwise.
Cap precision at 8 in validation: 64-bit range leaves ~9.2 billion whole units at scale 8. Never store ETH at native 18 (caps at 9.2 ETH). If display ever needs to differ from storage, add display_precision then.
PHP int is 64-bit signed and matches bigint exactly — plain integer math, no bcmath.

## Commodity price modeling decisions
commodity_prices rows are immutable data points: price decimal(24,12) ALWAYS denominated in the base currency (USD) — there is deliberately no price_commodity_id; priced_at timestamptz (not date — intraday crypto points wanted); created_at only, no updated_at, hard deletes allowed. Unique (commodity_id, priced_at).
This is the documented carve-out from the bigint-amounts rule: prices are never summed, need more precision than any commodity's display precision, and Postgres numeric is exact (tests run on Postgres). Ledger AMOUNTS remain bigint minor units — never copy this pattern for them.
Valuation math: exact bigint amount x numeric price via bcmath when exactness matters; floats acceptable for charting only.

## Domain prefixes: financial_ tables, App\Models\Financial namespace
Finance-domain tables are prefixed financial_ (financial_accounts, financial_commodities, financial_commodity_prices, financial_institutions) and their models live in App\Models\Financial (factories in Database\Factories\Financial) with explicit protected $table. The Financial sub-namespace carries through the whole domain: App\Enums\Financial, App\Http\Controllers\Api\V1\Financial, App\Http\Requests\Financial, App\Http\Resources\Financial. Future domains get their own prefix + namespaces the same way. API URLs carry the domain too: /api/v1/financial/accounts, route names financial.accounts.index (Route::prefix('financial')->name('financial.')).
Cross-domain artifacts (users, user_permissions, the Permission enum, UserResource) stay unprefixed at their namespace roots.
Use Rule::unique(Model::class)/Rule::exists(Model::class) in validation, never string table names — this is what made the rename safe.
