---
paths:
  - 'app/Models/Financial/**'
  - app/Models/Financial/CommodityPrice.php
---

# Financial

## Lot tracking: explicit financial_lots rows, soft negative-lot validation
Lots are explicit rows (financial_lots: financial_commodity_id, acquired_at date, cost = TOTAL acquisition cost in base-currency bigint minor units, metadata), never derived PTA-style with booking algorithms. Postings carry a nullable financial_lot_id: one posting touches at most one lot — a multi-lot sale is one reduction posting per lot. Lot quantity and location are DERIVED: SUM(amount) of referencing postings, per account. acquired_at defaults to the transaction date but is editable (in-kind transfers carry older basis).
Negative-lot states are SOFT: never block saves that drive a lot negative (backdating, edits, oversell) — a derived checker walks the lot's postings in (date, transaction id, position) order and flags the first posting taking the running sum below zero. Hard validation is structural only: lot commodity must equal posting commodity. Partial consumption allocates basis pro-rata in integer math (rounding rule chosen at implementation).

## Journal design: balance at cost, posting-level status, derived transaction state
Transactions balance beancount-style AT COST (no equity:conversion ceremony): value lot-bearing legs at allocated lot basis, USD legs at face — must sum to zero. Sales therefore carry an explicit income:capital-gains posting; realized gains are journal data, never derived at report time. Balance is HARD-validated at save; a lot edit that retroactively unbalances an old transaction becomes a soft checker issue (same mechanism as negative lots), never a blocked save.
Status lives on postings only (Pending/Cleared/Reconciled) — reconciliation is per account. Transaction status is DERIVED from its Asset/Liability postings (aggregation rule settled at implementation). No drafts, no voids: deletes are guarded instead. Posting order within a transaction is an explicit position column, unique per transaction.

## Commodity prices use exact decimals
Commodity prices are NUMERIC(78,36) and cast to Brick Math BigDecimal. Assign them from strings, integers, or BigDecimal values—never floats. Prices remain distinct from atomic commodity quantities, which use BigInteger.
