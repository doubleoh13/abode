---
paths:
  - 'app/Support/Financial/**'
---

# Support Financial

## Report valuation mirrors the SPA: exact-or-null, price as of end of UTC day, lots-endpoint basis denominator
Server-side report valuation (BalanceSheetBuilder) must stay bit-for-bit with money.ts: currencies at face; priced holdings at price x quantity, returning NULL (never rounding) when the exact product exceeds 25 fractional places or 53 integer digits. "Price as of date D" means the last priced_at strictly before D+1 (UTC day semantics, matching timestamptz comparison to a date string). Cost basis allocates each lot's total cost via CostBasisBalancer::allocate with the lot's ALL-TIME positive-posting sum as denominator — same as LotController's acquired_quantity, so report numbers match the account/commodity pages. Known quirk inherited from that convention: an in-kind transfer's positive posting inflates the denominator, so allocated bases can sum below the lot cost.
