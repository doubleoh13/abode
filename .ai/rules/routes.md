---
paths:
  - 'routes/**'
  - routes/console.php
  - routes/api.php
---

# Routes

## Public API routes: setup and token login only
routes/api.php has exactly one public group: GET/POST /api/v1/setup (first-run flow) and POST /api/v1/login. Setup GET always answers (the SPA guard needs the status); POST 404s once any user exists — the same hide-the-surface convention as /dev/login. POST /api/v1/login (LoginController, throttle:login — Fortify's per-email+ip limiter) exchanges email + password + device_name for a Sanctum token in the same envelope as POST tokens (data + plain_text_token); it is for the native app, never the SPA, which stays on session cookies via Fortify's /login. POST /api/v1/logout revokes the bearer token that authenticated the request and 409s for a session login (the SPA logs out through Fortify's /logout). Everything else stays inside the auth:sanctum group. Sanctum stateful is same-origin by config (config/sanctum.php uses the current-request-host placeholder), so any host the app is browsed at is first-party — LAN hostname, IP, or the real domain. Stateful-session endpoints on /api/* still need a matching Origin/Referer on the request: in Pest pass ['Referer' => 'http://localhost'] (phpunit.xml pins APP_URL=http://localhost so test request hosts match), and a request with no Referer gets no session — code touching $request->session() on an api route must guard with hasSession() (SetupController does).

## Scheduled commands must log their output on failure
schedule:run executes each command as a subprocess with stdout/stderr sent to /dev/null (outside Laravel Cloud), so an exception inside a scheduled command never reaches the container's stderr - only the "Running [...] FAIL" line and a bare "failed with exit code" Exception surface. Every Schedule::command gets ->onFailure(function (Stringable $output) { Log::error(...) }) (the parameter must be named $output) so the run's output lands in docker logs, and commands that swallow per-item failures call report($exception) so Sentry receives the real trace instead of only a non-zero exit.

## Every API route change re-exports the OpenAPI spec into the Android project
After any change to routes/api.php (or to a request/resource that changes an endpoint's shape), run `php artisan scramble:export --path=../abode-android/openapi.json --no-interaction` so the native app always has the current contract. The file lives at the root of the sibling abode-android checkout and is not tracked in this repo. Five pre-existing VR001/VR002 warnings from UpdateProfileRequest and UpdateAccountRequest (rules that read $this->route()) are expected and harmless.
