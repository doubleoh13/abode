---
paths:
  - app/Listeners/RecreateDevelopmentUser.php
---

# Listeners

## migrate:fresh preserves only development access
After a local database refresh, recreate only Jake's development user, finance permissions, and optional deterministic Sanctum token. Do not run DevelopmentFinancialSeeder from the DatabaseRefreshed hook; sample finance data is seeded only when invoked explicitly.
