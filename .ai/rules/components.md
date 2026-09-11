---
paths:
  - resources/js/components/TransactionForm.vue
---

# Components

## Transaction form keyboard: Enter saves when balanced, Ctrl+Enter saves and starts another
The form is a real <form @submit>, so Enter in any field submits (save and close). The one exception: Enter on the LAST amount input while the transaction is unbalanced adds a posting instead (handleAmountEnter); once residual is 0 it falls through and saves. Ctrl+Enter / Cmd+Enter anywhere runs saveAndAddAnother when canSaveAndContinue (creating a plain transaction: not editing, not a schedule) — it POSTs, emits savedAndContinued (parents refresh but keep the modal open), then resets payee/memo/postings while keeping the date and defaultAccountId, and refocuses the date. Buttons are right-aligned in the order Cancel · Save and new · Save (primary last). Label is "Save and new", not "Save and add another".
