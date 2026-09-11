# Roadmap

Working list for Abode. Add ideas as they come up; move items to Done with the commit that shipped them.

## Next

- SimpleFIN follow-ups: decide whether pending bank rows stay visible in the band; a SimpleFIN row's payee/description could seed a payee alias table for better auto-payee on convert.
- Duplicate candidate finder: surface likely pairs by account, amount, and a date window; each pair opens the merge dialog.
- Accounts index balances: show a USD value and holdings count per row as of today, reusing the balance sheet builder.
- Payee hygiene: search box, transaction count per payee, and a merge action that reassigns and deletes.

## Backlog

- Parent account pages roll up descendants into holdings and register instead of showing "No postings yet".
- Upcoming band above the journal register for future-dated transactions, muted and separate from the paginated history.
- Account balances shown without an as-of date currently include future-dated postings; decide whether displayed balances default to today.
- Net Worth Over Time report: BalanceSheetBuilder sampled at period ends, hand-rolled SVG like the price chart.
- Income Statement report.
- Investment Performance report. Open question: attributing realized gains per commodity; start by inferring from sibling lot postings in the sale transaction.
- Reconciliation batch action: assert a balance and mark everything through that date reconciled in one step.
- Recurring schedules with lot-bearing legs (a recurring brokerage buy needs the day's price).
- Business-day adjustment for schedules (previous or next business day when a due date lands on a weekend).
- Expose the default recurring lead window through the API so the form's placeholder isn't hardcoded to the config value.

## Decide

- Liabilities tile shows a negative number under a heading that already says liabilities. Keep the sign or show the magnitude?
- Institutions: none exist and the account tree already encodes the institution as its top segment. Drop the concept or start using it?
- In-kind transfers inflate a lot's acquired-quantity denominator, so allocated bases can sum below lot cost. Affects the lots endpoint, account and commodity pages, and the balance sheet equally.

## Done

- Reconciled postings are read-only in the transaction form (memo still editable) behind an explicit Unreconcile; the update API rejects field changes or removal of a posting that stays reconciled (2026-09-11).
- Balance assertion form: optional "mark this account's postings through this date reconciled"; applies only when the journal matches the balance and only to the asserted account's own legs (2026-09-11).
- SimpleFIN chunk 7: financial:simplefin-sync every four hours; a matched pending bank row that settles clears a Pending posting (never touches Cleared/Reconciled); header shows the bank balance and the difference from the ledger; register and form flag a matched posting whose amount differs from the bank row (2026-09-11).
- Register: a hover icon beside the payee opens the line's transaction in the form, where a matched bank row shows beneath its posting with an Unmatch action (POST bank-transactions/{id}/unmatch frees the row and declines that posting); a +n counterparty expands to list every counter leg with amounts. Account path prefixes capitalized (Assets:, Liabilities:, Income:, Expenses:, Equity:) (2026-09-11).
- SimpleFIN chunk 6: hand matching — Match on a band row puts the register in pick mode (unmatched status-bearing lines clickable, others dimmed, Esc or Cancel exits); clicking a line calls POST bank-transactions/{id}/match (2026-09-11).
- SimpleFIN chunk 5: a bank row converts to a new transaction from the account page — TransactionForm prefilled from the row (date, payee by name, description as memo, this account's leg, balancing empty counter leg) and postings.*.financial_bank_transaction_id links the row atomically in TransactionWriter. Status column redesigned: _ / C / lock glyphs, cyan when matched, set from a dismissable menu (2026-09-11).
- SimpleFIN chunk 4: BankTransactionMatcher proposes a posting per bank row when amount matches, dates are within 3 days, and the pairing is unambiguous; register line shows the bank row with Approve (links, clears a pending posting once the bank posts it) and Reject (records the posting in rejected_posting_ids, row returns to the band) (2026-09-11).
- SimpleFIN chunk 3: GET financial/bank-transactions?financial_account_id lists an account's unmatched bank rows; the account page shows them in a Bank band above the register, refreshed after every sync (2026-09-11).
- SimpleFIN chunk 2: financial_bank_transactions staging table, per-account sync endpoint with a 30-day first window and 7-day overlap, Sync action and last-synced label in the account header, bank balance stored on the account (2026-09-11).
- SimpleFIN chunk 1: access URL in env, accounts endpoint cached an hour, account form picker to map an account to a SimpleFIN account (2026-09-11).

- Journal filter bar: search plus preset chips (this month, last month, last 30 days, this year, pending, unreconciled) with the detailed filters collapsed behind a Filters toggle (2026-09-10).

- DateInput replaces every native date picker (ISO text, arrow-key nudges, T for today); modal focus trap and restore; secondary form buttons out of the tab order; Enter on the last amount adds a posting (2026-09-10).

- Recurring transactions with scheduled posting, per-schedule lead window, journal Repeat toggle and Duplicate, Schedules page (74bb5fd, 2026-09-10).
- Merge two transactions from the journal (89594d6, 2026-09-10).
- Visual review pass: touch-visible row actions, Finances landing page, page titles, register headers, narrower simple modals, commodity and journal wrapping (2026-09-10).
