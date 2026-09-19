<?php

namespace App\Http\Controllers;

use App\Models\ProxyHost;
use App\Models\User;
use App\Services\ProxyAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Login bridge for reverse proxy hosts with "require NAS login" enabled.
 *
 * Apache redirects unauthenticated visitors of a protected proxy domain to
 * the NAS login page; after a successful NAS login this controller issues a
 * session token and redirects the visitor back to the proxy domain, where a
 * one-time grant endpoint sets the token cookie.
 */
class ProxyAuthController extends Controller
{
    public function __construct(private ProxyAuthService $proxyAuthService) {}

    /**
     * Runs on the NAS domain: checks the NAS session, verifies the user is
     * allowed on the target host and redirects to the proxy-domain grant URL.
     */
    public function login(Request $request): RedirectResponse
    {
        $target = $this->proxyAuthService->parseRedirectTarget((string) $request->query('redirect', ''));

        if ($target === null) {
            return redirect('/');
        }

        // Apache carries the original query parameters as separate QSA
        // parameters; re-attach them to the target path. When the redirect
        // came from the grant bounce it already embeds its query string.
        $originalQuery = $request->query->all();
        unset($originalQuery['redirect']);

        $queryString = $target['query'] !== ''
            ? $target['query']
            : http_build_query($originalQuery);

        $redirect = $target['path'].($queryString !== '' ? '?'.$queryString : '');

        if (! Auth::check()) {
            // Send the visitor to the NAS login page and come back here
            // afterwards. The handoff flag marks the intended URL: after the
            // login the visitor must return to this bridge via a full-page
            // navigation, because this bridge redirects to the proxy domain
            // (an Inertia XHR must not follow that chain itself - CORS).
            $request->session()->put('url.intended', $request->fullUrl());
            $request->session()->put('proxy_auth_handoff', true);

            return redirect()->route('login', ['proxy_redirect' => $request->fullUrl()]);
        }

        /** @var User $user */
        $user = Auth::user();

        $host = ProxyHost::query()
            ->where('domain', $target['host'])
            ->where('auth_enabled', true)
            ->first();

        if ($host === null) {
            return redirect('/');
        }

        if (! $host->allowsUser($user->id)) {
            abort(403, 'Your NAS account is not authorized to access this application.');
        }

        $grant = $this->proxyAuthService->issueToken($user, $request->session()->getId());

        // The URL only ever carries a short-lived single-use nonce, never
        // the cookie capability itself.
        $grantUrl = $this->proxyAuthService->proxyHostScheme($host).'://'.$host->domain
            .ProxyAuthService::BRIDGE_PATH.'/grant?'.http_build_query([
                'nonce' => $grant['nonce'],
                'redirect' => $redirect,
            ]);

        return redirect()->away($grantUrl)->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * Runs on the proxy domain (served locally by the proxy vhost, never
     * proxied): validates the one-time token from the URL and sets the
     * session token cookie.
     */
    public function grant(Request $request): RedirectResponse
    {
        $token = $this->proxyAuthService->redeemGrant((string) $request->query('nonce', ''));

        $redirect = $request->query('redirect', '');

        $host = ProxyHost::query()
            ->where('domain', strtolower($request->getHost()))
            ->where('auth_enabled', true)
            ->first();

        if ($token === null || $host === null || ! $host->allowsUser($token->user_id)) {
            // Bounce back through the NAS bridge, which either asks for the
            // NAS login or shows the access denied message.
            $nas = $this->proxyAuthService->nasBaseUrl();
            $redirectTarget = is_string($redirect) && str_starts_with($redirect, '/')
                ? $request->getHost().$redirect
                : $request->getHost();

            return redirect()->away(
                $nas['scheme'].'://'.$nas['host'].'/proxy-auth/login?'.http_build_query(['redirect' => $redirectTarget])
            );
        }

        $cookie = cookie(
            ProxyAuthService::COOKIE_NAME,
            $token->token_hash,
            ProxyAuthService::TOKEN_TTL_DAYS * 24 * 60,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'Lax'
        );

        $target = is_string($redirect) && preg_match('#^/[^\r\n]*$#', $redirect) === 1 ? $redirect : '/';

        $response = redirect()->to($target);
        $response->headers->setCookie($cookie);
        // Never leak the grant URL (and any residual params) as a Referer to
        // the proxied application.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
