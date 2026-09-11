---
paths:
  - 'app/Http/Controllers/Api/V1/Financial/**'
  - app/Http/Controllers/Api/V1/Financial/BankTransactionController.php
  - app/Http/Controllers/Api/V1/Financial/BalanceAssertionController.php
---

# V1 Financial

## Transactions index is the paginated exception
Every financial index returns the full ordered collection EXCEPT transactions: unbounded growth, so TransactionController::index paginates (paginate(50), date desc then id desc) and the SPA renders a Prev/Next pager against the standard {data, links, meta} envelope. Don't paginate the small reference collections; do paginate anything else journal-shaped that grows forever.

## Reports pattern: one ReportController method per report under /financial/reports/*
Financial reports are read-only query endpoints on ReportController (Scramble group "Financial / Reports"), one method per report, routed as GET financial/reports/{report-name} named financial.reports.{report-name}, behind the view-finances gate. Responses are plain data-wrapped JSON (like the balances/price-series endpoints): decimal strings stripped of trailing zeros, per-account rows plus server-computed signed totals. The SPA mirrors this at /finances/reports (ReportsPage index) with one page per report; heavy derivation lives in an App\Support\Financial builder class, not the controller.

## Unmatch a bank row via POST bank-transactions/{id}/unmatch, never a DB edit
Unmatch nulls financial_posting_id AND appends the freed posting id to rejected_posting_ids, so the row drops back to the band without being re-proposed as an exact match against the posting it just left. It leaves the posting's status alone (approve may have cleared it; the user decides). 409 when the row is not matched. The SPA exposes it as "Unmatch" on the bank line beneath a posting in TransactionForm (PostingRow shows draft.bankTransaction for persisted postings only), confirmed with confirm(); the form emits bankTransactionUnmatched so the account register and journal refresh without saving the form.

## Reconcile-on-assert touches only the asserted account's own legs, and only when the balance holds
POST balance-assertions accepts reconcile_postings (boolean). The assertion is always saved; the response carries holds (journal sum through asserted_at equals balance) and reconciled_postings. Postings are marked Reconciled only when holds is true AND the flag is set, and only postings in the asserted account + commodity dated <= asserted_at that carry a status — counter legs in other accounts are never changed, matching "reconciliation is per account". A failing assertion reconciles nothing; the SPA alerts and leaves statuses as they were.
