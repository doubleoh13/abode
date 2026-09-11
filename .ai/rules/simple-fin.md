---
paths:
  - 'app/Support/Financial/SimpleFin/**'
---

# Simple Fin

## Sync runs every four hours; a settling bank row clears only a Pending posting
financial:simplefin-sync (SyncSimpleFinAccounts) runs everyFourHours via routes/console.php with the shared onFailure logger; it syncs each mapped account in turn, report()s and continues past a failing one, and exits non-zero if any failed. The header Sync button stays for on-demand pulls. In SimpleFinImporter::upsert, a row that goes pending → posted and is linked to a posting flips that posting from Pending to Cleared only; Cleared and Reconciled are never touched. An amount disagreement between a matched row and its posting is NOT auto-corrected — the SPA flags it (danger line under the payee on the register, highlighted amount in the form) for a person to fix or unmatch.
