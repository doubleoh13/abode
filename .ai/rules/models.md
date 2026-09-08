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
display_precision is display decimals per commodity (0–25), formatting only — storage scale is fixed at NUMERIC(78,25) and never rescales (see .ai/rules/financial.md).
symbol + symbol_placement (prefix/suffix) render "$1,234.56" vs "10.500 SPAXX"; both null = code-as-suffix. They are a pair: validation requires placement with symbol.
USD-as-base-currency is config-in-code: Commodity::BASE_CURRENCY_CODE plus the memoized Commodity::baseCurrency() helper — never a flag column. Lots/cost basis are designed in .ai/rules/financial.md.

## Amount storage: fixed 25-place decimals
Posting amounts, lot costs, and commodity prices are NUMERIC(78,25) cast to Brick Math BigDecimal — the full rule (bounds, rejection over rounding, display_precision, basis allocation) lives in .ai/rules/financial.md. Never coerce exact financial values through PHP int or float.

## Commodity price modeling decisions
commodity_prices rows are immutable data points: price NUMERIC(78,25) ALWAYS denominated in the base currency (USD) — there is deliberately no price_commodity_id; priced_at timestamptz (not date — intraday crypto points wanted); created_at only, no updated_at, hard deletes allowed. Unique (commodity_id, priced_at).
Valuation math: exact BigDecimal amount x price when exactness matters; floats acceptable for charting only.

## Domain prefixes: financial_ tables, App\Models\Financial namespace
Finance-domain tables are prefixed financial_ (financial_accounts, financial_commodities, financial_commodity_prices, financial_institutions) and their models live in App\Models\Financial (factories in Database\Factories\Financial) with explicit protected $table. The Financial sub-namespace carries through the whole domain: App\Enums\Financial, App\Http\Controllers\Api\V1\Financial, App\Http\Requests\Financial, App\Http\Resources\Financial. Future domains get their own prefix + namespaces the same way. API URLs carry the domain too: /api/v1/financial/accounts, route names financial.accounts.index (Route::prefix('financial')->name('financial.')).
Cross-domain artifacts (users, user_permissions, the Permission enum, UserResource) stay unprefixed at their namespace roots.
Use Rule::unique(Model::class)/Rule::exists(Model::class) in validation, never string table names — this is what made the rename safe.

## Polymorphics: enforced morph map, Notes/Attachments conventions
The morph map is ENFORCED (AppServiceProvider): every morphable model needs an alias ('user', 'financial.account', ...) — FQCNs never hit the database (namespace moves proved why). Adding a morphable model without registering it throws, including Sanctum's tokenable.
Notes and Attachments are global cross-domain polymorphics (App\Models, unprefixed tables): nullable user_id author, soft deletes (timestampsTz + softDeletesTz). Models opt in via App\Models\Concerns\HasNotes / HasAttachments.
Attachments store disk/path/name/mime_type/size plus sha256 hash (indexed, for dedup); files stay on disk through soft delete — removal belongs with a future force-delete. Generic APIs at /api/v1/notes and /api/v1/attachments address parents by (morph alias, id); index endpoints require the filter pair.
