---
paths:
  - 'app/Http/Requests/Financial/**'
  - app/Http/Requests/Financial/UpdateTransactionRequest.php
---

# Requests Financial

## Status applies only to reconcilable accounts
Asset and liability postings require a valid status. For income, expense, and equity accounts, normalize any submitted status to null before validation on create and update; do not reject an otherwise valid request for an irrelevant status.

## Account postability: leaves always postable, parents need allow_postings
A posting may target a leaf account unconditionally; an account with children rejects postings (422 in the transaction requests) unless its allow_postings flag is set. allow_postings is normalized to false for leaf accounts on save (same normalize-don't-reject pattern as posting status) — a leaf is always postable and the flag only ever matters on parents. The SPA's posting account picker hides non-postable accounts (but keeps the currently selected one visible when editing history). Existing journal history is never re-validated: an account becoming non-postable by gaining a child affects only new postings. The migration backfilled allow_postings=true for parents that already had postings.

## postings.*.financial_bank_transaction_id links a bank row on transaction write
StoreTransactionRequest (and its subclasses Update/Merge/Recurring by inheritance) accepts an optional postings.*.financial_bank_transaction_id. validateBankTransactions rejects a row from another account, a row already linked to a different posting, or the same row on two postings. TransactionWriter::syncPostings sets the row's financial_posting_id to the created/updated posting inside the same DB transaction, so "convert a bank row into a new transaction" is one request with no half-linked state. The SPA's TransactionForm takes a bankTransaction prop and prefills date, payee (matched by name against the payee list, else left for the user), memo (bank description), the account leg with the bank amount and status (pending row → pending, else cleared) carrying the id, and a balancing empty counter leg. Prefer this over a follow-up POST .../match after creating.

## Reconciled postings are read-only until explicitly unreconciled
UpdateTransactionRequest::validateReconciledPostings: a persisted posting whose stored status is Reconciled may not change account, commodity, lot, or amount while the payload keeps status 'reconciled' (422 on postings.{i}.status), and may not be dropped from the payload (422 on postings). Memo, and the transaction's own date/payee/memo, stay editable. Sending the posting back with any other status IS the unreconcile and unlocks it in the same request. The SPA mirrors this: PostingRow renders a locked posting (draft.locked, set from status on load) as read-only text with a "+ Memo" field; its status cell is the same PostingStatusMenu used on the register, and picking any non-reconciled status there is the unreconcile — it unlocks the row locally, nothing persists until Save. There is no separate Unreconcile button.
