<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shows a password page before anything on a staging site, so it cannot be browsed or crawled. A right password sets
 * a cookie for 30 days; the page is an ordinary form, so browsers' password managers save it (a basic-auth pop-up was
 * swallowed by some browser extensions). Scripts may still send the password as basic auth. Off while
 * STAGING_PASSWORD is empty and never in production.
 */
class RequireStagingPassword
{
    public const COOKIE = 'staging_pass';

    private const FORM_PATH = 'staging-password';

    /**
     * Called by services with nobody to type a password: payment webhooks, the uptime check, the web server's domain
     * check, the API (it carries its own bearer token in the same header) and the contact form static sites post to.
     */
    private const OPEN_PATHS = ['webhooks/*', 'up', 'internal/*', 'api/*', 'site/*/contact-form'];

    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('app.staging_password.password');

        // A signed address is already unguessable; the website builder's headless Chrome opens pages that way.
        if ($password === '' || app()->isProduction() || $request->is(...self::OPEN_PATHS) || $request->hasValidSignature()) {
            return $next($request);
        }

        if ($request->isMethod('POST') && $request->is(self::FORM_PATH)) {
            return $this->checkPassword($request, $password);
        }

        if (hash_equals(self::cookieValue($password), (string) $request->cookies->get(self::COOKIE))
            || (hash_equals((string) config('app.staging_password.user'), (string) $request->getUser())
                && hash_equals($password, (string) $request->getPassword()))) {
            return $next($request);
        }

        return $this->passwordPage($request->getRequestUri(), false);
    }

    /** Ties the cookie to this password and app key, so changing either signs everyone out. */
    public static function cookieValue(string $password): string
    {
        return hash_hmac('sha256', 'staging-password|'.$password, (string) config('app.key'));
    }

    private function checkPassword(Request $request, string $password): Response
    {
        $key = 'staging-password|'.$request->ip();
        $back = (string) $request->input('back', '/');
        // Only a path on this site, never another site ("//evil" or "/\evil" would leave it).
        $back = preg_match('#^/(?![/\\\\])#', $back) ? $back : '/';

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->passwordPage($back, true, 429);
        }

        if (! hash_equals($password, (string) $request->input('password'))) {
            RateLimiter::hit($key, 60);

            return $this->passwordPage($back, true);
        }

        RateLimiter::clear($key);

        return redirect($back)->withCookie(new Cookie(
            self::COOKIE, self::cookieValue($password), now()->addDays(30), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX,
        ));
    }

    private function passwordPage(string $back, bool $wrong, int $status = 401): Response
    {
        return response()->view('staging-password', [
            'back' => $back,
            'wrong' => $wrong,
            'tooMany' => $status === 429,
            'user' => (string) config('app.staging_password.user'),
        ], $status, ['X-Robots-Tag' => 'noindex, nofollow', 'Cache-Control' => 'no-store']);
    }
}
