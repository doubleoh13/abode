---
paths:
  - 'app/Http/Controllers/Api/V1/Financial/**'
---

# V1 Financial

## Transactions index is the paginated exception
Every financial index returns the full ordered collection EXCEPT transactions: unbounded growth, so TransactionController::index paginates (paginate(50), date desc then id desc) and the SPA renders a Prev/Next pager against the standard {data, links, meta} envelope. Don't paginate the small reference collections; do paginate anything else journal-shaped that grows forever.
