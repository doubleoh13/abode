---
paths:
  - 'docker/apache/**'
---

# Apache

## The vhost must re-export the Authorization header
docker/apache/vhost.conf sets AllowOverride None, so public/.htaccess is inert and the vhost owns every rule it contains — keep them in sync. The critical one: Apache does not expose Authorization to PHP (it reaches getallheaders() but never $_SERVER, so Symfony/Laravel see no bearer token and every token-authenticated request 401s), which the `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` line fixes. Pest never catches this — it is Apache-only; verify bearer auth with curl against a running container.
