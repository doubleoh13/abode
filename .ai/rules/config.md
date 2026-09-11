---
paths:
  - config/sentry.php
---

# Config

## Error tracking is Sentry, DSN is the only env value
sentry/sentry-laravel is wired via Integration::handles() in bootstrap/app.php. The SDK is inert without a DSN, so local, CI, and forks send nothing; production sets SENTRY_LARAVEL_DSN (listed in docker/env.production.example). config/sentry.php stays trimmed and opinionated: release comes from APP_BUILD_SHA (baked into the image), environment falls back to APP_ENV, send_default_pii and sql_bindings are off because this is finance data, and performance tracing is deliberately not enabled. GitHub issue creation is configured in the Sentry project (GitHub integration + alert rule), not in the repo.
