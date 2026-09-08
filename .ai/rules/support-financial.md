---
paths:
  - app/Support/Financial/CostBasisBalancer.php
---

# Support Financial

## Beancount importer mirrors CostBasisBalancer allocation
~/finances/scripts/import_to_abode.py (the one-shot Beancount migration importer) re-implements this class's pro-rata allocation (floor at 1e-25, largest-remainder shortfall distribution, ties by input order) to rebalance interpolated ledger legs and to price in-kind transfer lots. Any change to allocation or residual semantics here must be mirrored there, alongside the existing JS parity guarded by `npm test`.
