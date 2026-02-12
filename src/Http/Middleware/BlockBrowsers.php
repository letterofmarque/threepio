<?php

declare(strict_types=1);

namespace Marque\Threepio\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Marque\Threepio\Support\TrackerResponse;

/**
 * Blocks browser requests to tracker endpoints.
 *
 * BitTorrent clients don't send cookies, accept-language, etc.
 * Browsers do. This middleware blocks obvious browser requests.
 */
class BlockBrowsers
{
    /**
     * Browser user agent patterns to block.
     */
    private const BROWSER_PATTERNS = [
        '/^Mozilla\//i',
        '/^Opera\//i',
        '/^Links /i',
        '/^Lynx\//i',
        '/^Chrome\//i',
        '/^Safari\//i',
        '/^MSIE/i',
        '/^Edge\//i',
        '/Gecko\//i',
        '/WebKit\//i',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        // Block if browser-specific headers are present
        if ($request->hasHeader('Cookie') ||
            $request->hasHeader('Accept-Language') ||
            $request->hasHeader('Accept-Charset')) {
            return TrackerResponse::error('Access denied');
        }

        // Block browser user agents
        $userAgent = $request->userAgent() ?? '';
        foreach (self::BROWSER_PATTERNS as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return TrackerResponse::error('Access denied');
            }
        }

        return $next($request);
    }
}
