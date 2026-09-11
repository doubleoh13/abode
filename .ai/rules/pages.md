---
paths:
  - resources/js/pages/AccountPage.vue
---

# Pages

## Bank band row actions: Match (register pick mode) and New transaction
Each unmatched bank row in the account page band has two hover actions. "Match" sets matchingBankTransaction: the chosen band row highlights, other band rows dim, and register lines that can take a link (status-bearing, no bank_transaction) become clickable (role=button, Enter works, accent hover) while the rest dim; clicking POSTs bank-transactions/{id}/match with that posting id and reloads band + register. Esc, the row's Cancel, or a completed match exit the mode; 409/422 surface via alert(). "New transaction" opens TransactionForm with the bankTransaction prop (see requests-financial rule). Proposed exact matches never appear in the band — they render on the register line with ✓/✕.

## Register scrolls instead of paging; the API stays paginated
The account register appends pages as the user scrolls: an IntersectionObserver (400px root margin) on a sentinel div after the list calls loadMorePostings, which keeps fetching page loadedPages+1 while the sentinel stays visible and pages remain. PostingController::index is still paginate(50) — the SPA just accumulates. loadPostings() re-reads every loaded page in parallel and concatenates, so status changes, matches and unmatches update in place without losing scroll position; resets (account change, hide-reconciled toggle) set loadedPages back to 1. Assertion markers older than the loaded span wait until their day scrolls in; the newest marker always shows because page 1 is always loaded. PaginationBar remains for the journal, which still pages.
