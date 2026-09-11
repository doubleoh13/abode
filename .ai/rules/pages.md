---
paths:
  - resources/js/pages/AccountPage.vue
---

# Pages

## Bank band row actions: Match (register pick mode) and New transaction
Each unmatched bank row in the account page band has two hover actions. "Match" sets matchingBankTransaction: the chosen band row highlights, other band rows dim, and register lines that can take a link (status-bearing, no bank_transaction) become clickable (role=button, Enter works, accent hover) while the rest dim; clicking POSTs bank-transactions/{id}/match with that posting id and reloads band + register. Esc, the row's Cancel, or a completed match exit the mode; 409/422 surface via alert(). "New transaction" opens TransactionForm with the bankTransaction prop (see requests-financial rule). Proposed exact matches never appear in the band — they render on the register line with ✓/✕.
