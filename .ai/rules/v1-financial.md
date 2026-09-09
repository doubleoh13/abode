---
paths:
  - 'app/Http/Controllers/Api/V1/Financial/**'
---

# V1 Financial

## Transactions index is the paginated exception
Every financial index returns the full ordered collection EXCEPT transactions: unbounded growth, so TransactionController::index paginates (paginate(50), date desc then id desc) and the SPA renders a Prev/Next pager against the standard {data, links, meta} envelope. Don't paginate the small reference collections; do paginate anything else journal-shaped that grows forever.

## Reports pattern: one ReportController method per report under /financial/reports/*
Financial reports are read-only query endpoints on ReportController (Scramble group "Financial / Reports"), one method per report, routed as GET financial/reports/{report-name} named financial.reports.{report-name}, behind the view-finances gate. Responses are plain data-wrapped JSON (like the balances/price-series endpoints): decimal strings stripped of trailing zeros, per-account rows plus server-computed signed totals. The SPA mirrors this at /finances/reports (ReportsPage index) with one page per report; heavy derivation lives in an App\Support\Financial builder class, not the controller.
