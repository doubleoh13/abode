<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureCookiesOnSecureRequests
{
    /**
     * Mark session and XSRF cookies Secure exactly when the connection is
     * secure, so plain-http access (LAN, pre-proxy) still works while
     * TLS-proxied traffic gets the Secure attribute.
     */
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.secure' => $request->isSecure()]);

        return $next($request);
    }
}
