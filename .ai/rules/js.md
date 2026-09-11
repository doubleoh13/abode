---
paths:
  - 'resources/js/**'
---

# Js

## Frontend is a plain Vue SPA that dogfoods the API
Vue 3 SPA with vue-router — deliberately no Inertia and no Livewire. Laravel serves one Blade shell (resources/views/app.blade.php) via the catch-all route named "spa"; the router owns all paths client-side.
All data access goes through /api/v1 (the same API the future native app will use), authenticated via Sanctum stateful session cookies (statefulApi middleware + /sanctum/csrf-cookie), never bearer tokens in the browser.
Auth backend is headless Fortify (views => false, features => []) — POST /login and /logout only. Auth state lives in resources/js/auth.js (plain reactive module, no Pinia).

## Frontend is TypeScript
All SPA code is TypeScript: .ts modules and <script setup lang="ts"> in SFCs (auth state lives in resources/js/auth.ts). Type-checking runs via vue-tsc — `npm run types` standalone, and as part of `npm run build`.
TypeScript is pinned to 5.x: vue-tsc cannot drive the Go-based TypeScript 7 compiler.

## UI interaction patterns for CRUD pages
CRUD pages follow one pattern (see PayeesPage as the minimal example): full-width layout, header row with h1 left + primary action button right, forms open in ModalDialog (animated, Escape/backdrop close), first field autofocused, per-field 422 errors rendered inline, row actions (Edit/Delete) as mono uppercase buttons revealed on row hover, native confirm() for deletes, 409 messages surfaced via alert().
Selects are always the ComboBox component (type-to-filter, arrow/Enter/Tab select, explicit "(none)" row when nullable) — never a native select. Shared form styling comes from the .input/.field-label/.button-primary/.button-subtle classes in app.css; never restyle inline.
Shared API types live in resources/js/types.ts; auth state stays in the plain reactive auth.ts module (no Pinia).

## Unscaled decimal API values and display-only precision
Financial API values (posting amounts, lot costs and quantities, journal residuals) are unscaled decimal strings, never numbers or commodity-scaled integers — never use Number for financial values. money.ts uses an internal fixed 25-place BigInt representation for exact calculations and converts back to decimal strings at boundaries. Commodity display_precision controls formatting only; editing preserves all stored fractional digits. Display uses half-up rounding; unit-cost products that exceed storage capacity are rejected.

## money.test.mjs guards PHP/JS allocation parity — run npm test
tests/Unit/money.test.mjs (Node's test runner, run via `npm test`) is the only automated check that money.ts allocation matches CostBasisBalancer bit-for-bit; both suites assert the same vectors. Run it whenever money.ts or CostBasisBalancer changes, and keep the shared vectors in sync in both test files.

## Posting status is set from a dismissable menu, never by cycling on click
Clicking a posting's status glyph opens components/PostingStatusMenu.vue: a small menu listing Pending / Cleared / Reconciled with glyphs, the current one checked. Choosing any option PATCHes /financial/postings/{id} with that status; Escape, Tab, click outside, scroll or resize dismiss without change so a mis-click costs nothing. The menu is teleported to body and positioned fixed from the trigger's rect because register lists use overflow-hidden for rounded corners. The old click-to-cycle (nextStatus) is gone — do not reintroduce it.

## Posting status column: _ / C / lock, cyan when matched to a bank row
Status glyphs come from components/PostingStatusGlyph.vue everywhere a posting status is shown: pending = "_" (never blank — the glyph is the click target for the status menu), cleared = C, reconciled = an inline SVG padlock. Never the old P/C/R letters or emoji. A posting linked to a bank row (posting.bank_transaction, loaded on the account register and journal) renders the whole status button in accent cyan via statusClass(matched); unmatched postings are muted, reconciled included. There is no separate "imported" marker next to the payee — the cyan status column is the only trace of a bank match. A proposed, not yet approved, match shows on the register line as an accent dot, bank date · payee, ✓ (approve) and ✕ (not a match).

## Unmatched-import dot comes from bankImports.ts, fed by the accounts index count
GET financial/accounts carries unmatched_bank_transactions_count (withCount of rows with a null financial_posting_id, proposals included). resources/js/bankImports.ts holds the per-account counts: AppShell refreshes it on mount and on every route change, AccountPage overwrites its own account's count after each bank-row load so approve/match/unmatch update the sidebar without navigating. The indicator is a size-1.5 bg-accent dot (title "Unmatched bank transactions") beside Finances and Accounts in the sidebar and beside the account name on the accounts list — no counts in the nav, no separate endpoint.
