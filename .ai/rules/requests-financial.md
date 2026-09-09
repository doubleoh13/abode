---
paths:
  - 'app/Http/Requests/Financial/**'
---

# Requests Financial

## Status applies only to reconcilable accounts
Asset and liability postings require a valid status. For income, expense, and equity accounts, normalize any submitted status to null before validation on create and update; do not reject an otherwise valid request for an irrelevant status.

## Account postability: leaves always postable, parents need allow_postings
A posting may target a leaf account unconditionally; an account with children rejects postings (422 in the transaction requests) unless its allow_postings flag is set. allow_postings is normalized to false for leaf accounts on save (same normalize-don't-reject pattern as posting status) — a leaf is always postable and the flag only ever matters on parents. The SPA's posting account picker hides non-postable accounts (but keeps the currently selected one visible when editing history). Existing journal history is never re-validated: an account becoming non-postable by gaining a child affects only new postings. The migration backfilled allow_postings=true for parents that already had postings.
