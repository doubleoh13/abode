---
paths:
  - 'app/Support/Financial/**'
---

# Support Financial

## Report valuation mirrors the SPA: exact-or-null, price as of end of UTC day, lots-endpoint basis denominator
Server-side report valuation (BalanceSheetBuilder) must stay bit-for-bit with money.ts: currencies at face; priced holdings at price x quantity, returning NULL (never rounding) when the exact product exceeds 25 fractional places or 53 integer digits. "Price as of date D" means the last priced_at strictly before D+1 (UTC day semantics, matching timestamptz comparison to a date string). Cost basis allocates each lot's total cost via CostBasisBalancer::allocate with the lot's ALL-TIME positive-posting sum as denominator — same as LotController's acquired_quantity, so report numbers match the account/commodity pages. Known quirk inherited from that convention: an in-kind transfer's positive posting inflates the denominator, so allocated bases can sum below the lot cost.

## Bank row matching: propose only plain, unambiguous pairs; rejection is per row
BankTransactionMatcher::proposals pairs an account's unmatched bank rows with USD postings that have no bank link, equal amount (BigDecimal isEqualTo), and |posted_on - transaction date| <= config('financial.simplefin.match_window_days') (3). A pair is proposed ONLY when the row has exactly one candidate and that posting has exactly one candidate row; anything ambiguous stays in the band for a person. Matching runs in PHP over the small unmatched set, not in SQL. The SPA pulls proposed rows out of the Bank band and renders them on the register line with Approve/Reject. Approve = POST bank-transactions/{id}/match (also used for hand matching); a Pending posting becomes Cleared only when the bank row is not pending. Reject = POST .../reject appends the posting id to rejected_posting_ids (jsonb list on the row) so it is never proposed again; the row drops back to the band. Both 409 when the row is already linked.
