<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Add hardening response headers (CSP, anti-clickjacking, anti-MIME-sniffing)
     * and strip framework fingerprinting headers.
     *
     * Findings addressed (OWASP ZAP):
     *  - Content Security Policy (CSP) header not set
     *  - Missing anti-clickjacking header
     *  - X-Content-Type-Options header missing
     *  - X-Powered-By information leak
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a per-request nonce *before* the view renders so the @vite /
        // @viteReactRefresh directives and our inline <script> can reference it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        // Anti-MIME-sniffing.
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Anti-clickjacking. CSP frame-ancestors is the modern control; the
        // X-Frame-Options header covers legacy browsers that ignore CSP.
        $response->headers->set('X-Frame-Options', 'DENY');

        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));

        // Don't leak full URLs (which can carry ids/tokens) to other origins.
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Lock down powerful browser features. The web DTR needs the camera
        // (selfie capture) and geolocation (punch location); everything else off.
        $response->headers->set(
            'Permissions-Policy',
            'camera=(self), geolocation=(self), microphone=(), payment=(), usb=(), interest-cohort=()',
        );

        // Block legacy Flash/PDF cross-domain policy files.
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS: once a browser has seen this over HTTPS, force HTTPS for a year
        // (incl. subdomains). Sent only over secure connections so it never pins
        // a local http:// dev box. This is the real defence against on-the-wire
        // credential sniffing (e.g. Wireshark) — see the login note below.
        if ($request->secure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload',
            );
        }

        // Suppress framework/runtime fingerprinting. header_remove() drops the
        // header PHP injects at the SAPI level; the second call covers anything
        // a downstream component added to the Symfony response bag.
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        return $response;
    }

    /**
     * Build the Content-Security-Policy.
     *
     * Inline scripts (the dark-mode bootstrap + Vite tags) are allowed via the
     * per-request nonce. Inline styles use 'unsafe-inline' because Radix/shadcn
     * primitives set runtime style attributes that cannot carry a nonce.
     */
    protected function contentSecurityPolicy(string $nonce): string
    {
        $scriptSrc = ["'self'", "'nonce-{$nonce}'"];
        $connectSrc = ["'self'"];

        // When the Vite dev server is running, HMR needs eval, its own origin
        // and a websocket. Only relax the policy in that case.
        if (app(\Illuminate\Foundation\Vite::class)->isRunningHot()) {
            $scriptSrc[] = "'unsafe-eval'";
            $scriptSrc[] = 'http://localhost:5173';
            $connectSrc[] = 'http://localhost:5173';
            $connectSrc[] = 'ws://localhost:5173';
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $scriptSrc),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connectSrc),
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        return implode('; ', $directives);
    }
}
