---
paths:
  - 'app/Models/Financial/**'
---

# Financial

## Lot tracking: explicit financial_lots rows, soft negative-lot validation
Lots are explicit rows (financial_lots: financial_commodity_id, acquired_at date, cost = TOTAL acquisition cost in USD as an exact decimal, metadata), never derived PTA-style with booking algorithms. Postings carry a nullable financial_lot_id: one posting touches at most one lot — a multi-lot sale is one reduction posting per lot. Lot quantity and location are DERIVED: SUM(amount) of referencing postings, per account. acquired_at defaults to the transaction date but is editable (in-kind transfers carry older basis).
Negative-lot states are SOFT: never block saves that drive a lot negative (backdating, edits, oversell) — a derived checker walks the lot's postings in (date, transaction id, position) order and flags the first posting taking the running sum below zero. Hard validation is structural only: lot commodity must equal posting commodity. Partial consumption allocates basis pro-rata under the fixed-decimal rule below.

## Journal design: balance at cost, posting-level status, derived transaction state
Transactions balance beancount-style AT COST (no equity:conversion ceremony): value lot-bearing legs at allocated lot basis, USD legs at face — must sum to zero. Sales therefore carry an explicit income:capital-gains posting; realized gains are journal data, never derived at report time. Balance is HARD-validated at save; a lot edit that retroactively unbalances an old transaction becomes a soft checker issue (same mechanism as negative lots), never a blocked save.
Status lives on postings only (Pending/Cleared/Reconciled) — reconciliation is per account. Transaction status is DERIVED from its Asset/Liability postings (aggregation rule settled at implementation). No drafts, no voids: deletes are guarded instead. Posting order within a transaction is an explicit position column, unique per transaction.

## Fixed 25-place decimal storage
Posting amounts, lot costs, and commodity prices use NUMERIC(78,25) and Brick Math BigDecimal: 53 integer digits and 25 fractional digits. Assign them from strings, integers, or BigDecimal values — never floats. API values are unscaled decimal strings; reject overflow/excess fractional digits rather than silently rounding. Commodity display_precision (0–25) controls formatting only and can change without rescaling data. Lot costs remain total USD costs. Basis allocation floors at 25 fractional places and distributes the combined-target shortfall by descending remainder, ties by posting order.
