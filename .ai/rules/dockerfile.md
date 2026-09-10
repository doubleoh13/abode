---
paths:
  - Dockerfile
---

# Dockerfile

## Dockerfile traps: opcache is built-in, composer scripts, stale bootstrap cache
php:8.5 images ship Zend OPcache compiled into core — `docker-php-ext-install opcache` fails with "cannot stat modules/*"; never list it. Vendor stage must use `composer install --no-dev --no-scripts` (post-autoload-dump needs the app; boost is dev-only), then the final stage runs dump-autoload + package:discover after COPY. `.dockerignore` must exclude `bootstrap/cache/*` — a dev machine's packages.php references Boost and breaks package:discover in the image. postgresql-client-18 comes from PGDG (matches compose postgres:18, whose data mount is /var/lib/postgresql, not .../data).
