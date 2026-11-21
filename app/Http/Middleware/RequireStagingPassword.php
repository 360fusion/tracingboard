<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Asks for one shared username and password (HTTP basic auth, which browsers can save) before anything on a staging
 * site is shown, so it cannot be browsed or crawled. Off while STAGING_PASSWORD is empty and never in production.
 */
class RequireStagingPassword
{
    /**
     * Called by services with nobody to type a password: payment webhooks, the uptime check, the web server's domain
     * check, the API (it carries its own bearer token in the same header) and the contact form static sites post to.
     */
    private const OPEN_PATHS = ['webhooks/*', 'up', 'internal/*', 'api/*', 'site/*/contact-form'];

    /**
     * Whether this site asks for the password. The page is told too, so the service worker leaves page loads to the
     * browser: Chrome cannot ask for a password on a load the worker makes, and fails it instead.
     */
    public static function isOn(): bool
    {
        return (string) config('app.staging_password.password') !== '' && ! app()->isProduction();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('app.staging_password.password');

        // A signed address is already unguessable; the website builder's headless Chrome opens pages that way.
        if (! self::isOn() || $request->is(...self::OPEN_PATHS) || $request->hasValidSignature()) {
            return $next($request);
        }

        if (hash_equals((string) config('app.staging_password.user'), (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('This is a private test site.', 401, [
            'WWW-Authenticate' => 'Basic realm="Tracing Board staging", charset="UTF-8"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
