---
paths:
  - 'app/Http/Requests/Financial/**'
---

# Requests Financial

## Status applies only to reconcilable accounts
Asset and liability postings require a valid status. For income, expense, and equity accounts, normalize any submitted status to null before validation on create and update; do not reject an otherwise valid request for an irrelevant status.
