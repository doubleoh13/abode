---
paths:
  - 'app/Http/Middleware/**'
---

# Middleware

## Cookie security follows the connection, not a flag
SecureCookiesOnSecureRequests (global, appended after TrustProxies in bootstrap/app.php) sets session.secure from $request->isSecure() on every request: plain-http access (LAN hostname, pre-proxy) gets working cookies, TLS-proxied traffic gets the Secure attribute. Never reintroduce a static SESSION_SECURE_COOKIE env/ENV — a true value silently breaks non-loopback http (browsers refuse Secure cookies there → 419s), which is how it was discovered.
