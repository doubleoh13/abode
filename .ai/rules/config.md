---
paths:
  - config/sentry.php
  - config/app.php
---

# Config

## Error tracking is Sentry, DSN is the only env value
sentry/sentry-laravel is wired via Integration::handles() in bootstrap/app.php. The SDK is inert without a DSN, so local, CI, and forks send nothing; production sets SENTRY_LARAVEL_DSN (listed in docker/env.production.example). config/sentry.php stays trimmed and opinionated: release comes from APP_BUILD_SHA (baked into the image), environment falls back to APP_ENV, send_default_pii and sql_bindings are off because this is finance data, and performance tracing is deliberately not enabled. GitHub issue creation is configured in the Sentry project (GitHub integration + alert rule), not in the repo.

## App timezone is UTC, schedule timezone is America/New_York
Storage, casts, and price points stay UTC (app.timezone). Schedules read in Eastern via app.schedule_timezone because the data is US-based: fund and 529 NAVs post in the evening Eastern, so the old midnight-UTC (8pm ET) price fetch ran before prices existed and silently stored nothing. Scheduled times must avoid the 01:00-03:00 DST changeover window. The price fetch runs dailyAt('05:00'); schedule:list shows the UTC-converted cron (0 9 or 0 10), which is expected.
