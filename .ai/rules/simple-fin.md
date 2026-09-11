---
paths:
  - 'app/Support/Financial/SimpleFin/**'
---

# Simple Fin

## Sync runs every four hours; a settling bank row clears only a Pending posting
financial:simplefin-sync (SyncSimpleFinAccounts) runs everyFourHours via routes/console.php with the shared onFailure logger; it syncs each mapped account in turn, report()s and continues past a failing one, and exits non-zero if any failed. The header Sync button stays for on-demand pulls. In SimpleFinImporter::upsert, a row that goes pending → posted and is linked to a posting flips that posting from Pending to Cleared only; Cleared and Reconciled are never touched. An amount disagreement between a matched row and its posting is NOT auto-corrected — the SPA flags it (danger line under the payee on the register, highlighted amount in the form) for a person to fix or unmatch.

## Fidelity SPAXX sweep rows are dropped by description pattern, never staged
Fidelity's cash management account reports every cash movement twice: the real row plus a core-position sweep ("REDEMPTION FROM CORE ACCOUNT ... (SPAXX)" for debits, "PURCHASE INTO CORE ACCOUNT ... (SPAXX)" for deposits, "REINVESTMENT ... (SPAXX)" for dividends). Sweeps are daily roll-ups with inconsistent signs (purchases arrive positive), so they cannot be netted or paired — the ledger treats SPAXX as cash and never models them. SimpleFinImporter::isIgnored skips any row whose description matches config('financial.simplefin.ignored_description_patterns') (regexes, config in code) before staging; sync results report an `ignored` count. The DIVIDEND RECEIVED row is real income and is kept. Add new patterns to the config list, never special-case in code; existing staged rows for a new pattern need a one-off delete.
