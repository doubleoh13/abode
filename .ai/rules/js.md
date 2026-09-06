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
