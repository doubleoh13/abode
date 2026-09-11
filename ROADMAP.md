# Roadmap

Working list for Abode. Add ideas as they come up; move items to Done with the commit that shipped them.

## Next

- SimpleFIN, in chunks: (4) opt-in balance assertions from the stored bank balance; (5) payee rules so repeat descriptions map to a payee and category.
- Duplicate candidate finder: surface likely pairs by account, amount, and a date window; each pair opens the merge dialog.
- Accounts index balances: show a USD value and holdings count per row as of today, reusing the balance sheet builder.
- Payee hygiene: search box, transaction count per payee, and a merge action that reassigns and deletes.

## Backlog

- Show a bank-link marker on journal postings that settle a bank transaction, and let a posting be unlinked from there.

- Bank rows whose id changes between pending and posted leave a stale pending row; handle in the inbox first, automate only if it recurs.

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

- SimpleFIN chunk 3: /finances/inbox lists unresolved bank rows with candidate postings (same account and amount within 5 days), Match, Create via the pre-filled transaction form, Ignore and Restore; landing page count (2026-09-11).

- SimpleFIN chunk 2: financial_bank_transactions staging table linked per posting, importer every four hours with a 30-day first window and 7-day overlap, bank balance and sync time on accounts, merge carries bank links (2026-09-11).

- SimpleFIN chunk 1: access URL in env, accounts endpoint cached an hour, account form picker to map an account to a SimpleFIN account (2026-09-11).

- Journal filter bar: search plus preset chips (this month, last month, last 30 days, this year, pending, unreconciled) with the detailed filters collapsed behind a Filters toggle (2026-09-10).

- DateInput replaces every native date picker (ISO text, arrow-key nudges, T for today); modal focus trap and restore; secondary form buttons out of the tab order; Enter on the last amount adds a posting (2026-09-10).

- Recurring transactions with scheduled posting, per-schedule lead window, journal Repeat toggle and Duplicate, Schedules page (74bb5fd, 2026-09-10).
- Merge two transactions from the journal (89594d6, 2026-09-10).
- Visual review pass: touch-visible row actions, Finances landing page, page titles, register headers, narrower simple modals, commodity and journal wrapping (2026-09-10).
