---
paths:
  - 'routes/**'
  - routes/console.php
---

# Routes

## Public API routes: setup only, hidden behind 404
routes/api.php has exactly one public group: GET/POST /api/v1/setup (first-run flow). GET always answers (the SPA guard needs the status); POST 404s once any user exists — the same hide-the-surface convention as /dev/login. Everything else stays inside the auth:sanctum group. Sanctum stateful is same-origin by config (config/sanctum.php uses the current-request-host placeholder), so any host the app is browsed at is first-party — LAN hostname, IP, or the real domain. Stateful-session endpoints on /api/* still need a matching Origin/Referer on the request: in Pest pass ['Referer' => 'http://localhost'] (phpunit.xml pins APP_URL=http://localhost so test request hosts match), and a request with no Referer gets no session — code touching $request->session() on an api route must guard with hasSession() (SetupController does).

## Scheduled commands must log their output on failure
schedule:run executes each command as a subprocess with stdout/stderr sent to /dev/null (outside Laravel Cloud), so an exception inside a scheduled command never reaches the container's stderr - only the "Running [...] FAIL" line and a bare "failed with exit code" Exception surface. Every Schedule::command gets ->onFailure(function (Stringable $output) { Log::error(...) }) (the parameter must be named $output) so the run's output lands in docker logs, and commands that swallow per-item failures call report($exception) so Sentry receives the real trace instead of only a non-zero exit.
