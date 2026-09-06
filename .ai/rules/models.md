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
