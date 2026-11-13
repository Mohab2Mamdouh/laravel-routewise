<?php

namespace Routewise\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * laravel/mcp only checks that a redirect URI *starts with* an allowed domain, so
 * any path on an allowed host would pass, including an open redirect there. Client
 * registration here accepts exactly the listed OAuth callbacks
 * (config routewise.exact_redirect_uris), plus loopback addresses on any port
 * for local clients such as Claude Code.
 */
class RestrictOAuthRedirects
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->is('oauth/register')) {
            foreach ((array) $request->input('redirect_uris', []) as $uri) {
                if (! is_string($uri) || ! self::allowed($uri)) {
                    return response()->json([
                        'error' => 'invalid_redirect_uri',
                        'error_description' => 'Only the configured OAuth callbacks and loopback addresses are accepted.',
                    ], 400);
                }
            }
        }

        return $next($request);
    }

    public static function allowed(string $uri): bool
    {
        if (in_array($uri, config('routewise.exact_redirect_uris', []), true)) {
            return true;
        }

        $parts = parse_url($uri);

        return ($parts['scheme'] ?? null) === 'http'
            && in_array($parts['host'] ?? null, ['localhost', '127.0.0.1', '[::1]'], true)
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
