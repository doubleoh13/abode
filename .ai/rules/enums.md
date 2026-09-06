---
paths:
  - 'app/Enums/**'
---

# Enums

## Enums live in app/Enums, domain enums in a domain sub-namespace
Cross-domain enums live in app/Enums (Permission is the pattern: string-backed, TitleCase cases, kebab-case values). Domain-specific enums live in the domain sub-namespace: App\Enums\Financial (AccountType, CommodityKind, SymbolPlacement). make:enum targets app/Enums once the directory exists — move domain enums into their sub-directory.
