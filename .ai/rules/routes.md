---
paths:
  - 'routes/**'
---

# Routes

## Public API routes: setup only, hidden behind 404
routes/api.php has exactly one public group: GET/POST /api/v1/setup (first-run flow). GET always answers (the SPA guard needs the status); POST 404s once any user exists — the same hide-the-surface convention as /dev/login. Everything else stays inside the auth:sanctum group. Stateful-session endpoints on /api/* (like setup's login+regenerate) only carry a session when the request's Origin/Referer matches sanctum's stateful domains — in Pest that means passing ['Referer' => 'http://localhost'] on the POST, and in a browser it means visiting via the APP_URL host.
