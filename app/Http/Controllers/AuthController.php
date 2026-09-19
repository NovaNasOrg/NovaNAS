<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProxyAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use OTPHP\TOTP;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function __construct(private ProxyAuthService $proxyAuthService) {}

    /**
     * Display the login page.
     */
    public function login(Request $request)
    {
        // Reverse proxy login bridge: remember where the visitor came from so
        // they are sent back after a successful login.
        $proxyRedirect = $request->query('proxy_redirect');

        if (is_string($proxyRedirect) && $proxyRedirect !== '') {
            $intended = $this->proxyAuthService->resolveSafeIntendedUrl($proxyRedirect);

            if ($intended !== null) {
                $request->session()->put('url.intended', $intended);
            }
        }

        return Inertia::render('Login', [
            'version' => config('app.version'),
            'passwordSet' => $request->boolean('password_set', false),
            'twoFactorRequired' => $request->session()->get('2fa_required', false),
            'twoFactorEmail' => $request->session()->get('2fa_email'),
        ]);
    }

    /**
     * Handle an authentication attempt.
     * If 2FA is enabled, store pending user in session and ask for TOTP code.
     */
    public function authenticate(Request $request): Response
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Check credentials without logging in
        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (! $user || ! Auth::getProvider()->validateCredentials($user, $credentials)) {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // Ensure we have a User model instance
        if (! $user instanceof User) {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ])->onlyInput('email');
        }

        // If 2FA is enabled, store pending auth in session
        if ($user->two_factor_enabled) {
            $request->session()->put('2fa_pending_user_id', $user->id);
            $request->session()->put('2fa_pending_remember', $request->boolean('remember'));

            return back()->with([
                '2fa_required' => true,
                '2fa_email' => $user->email,
            ]);
        }

        // No 2FA — log in directly
        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return $this->postLoginResponse($request);
    }

    /**
     * Verify a 2FA TOTP code and complete the login.
     */
    public function verifyTwoFactor(Request $request): Response
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $pendingUserId = $request->session()->get('2fa_pending_user_id');

        if (! $pendingUserId) {
            return redirect('/login');
        }

        $user = User::find($pendingUserId);

        if (! $user || ! $user->two_factor_enabled) {
            $request->session()->forget(['2fa_pending_user_id', '2fa_pending_remember']);

            return redirect('/login');
        }

        $totp = TOTP::createFromSecret($user->two_factor_secret);
        $totp->setLabel($user->email);
        $totp->setIssuer(config('app.name', 'NovaNAS'));

        if (! $totp->verify($validated['code'])) {
            return back()->withErrors([
                'code' => 'Invalid verification code. Please try again.',
            ]);
        }

        // Code is valid — complete the login
        $remember = $request->session()->pull('2fa_pending_remember', false);
        $request->session()->forget('2fa_pending_user_id');

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return $this->postLoginResponse($request);
    }

    /**
     * Build the post-login redirect. When the intended URL is part of the
     * reverse proxy login bridge (marked with the handoff flag) or lives on
     * another origin, Inertia XHR requests must not follow the redirect chain
     * themselves (the bridge redirects cross-origin - CORS); respond with
     * X-Inertia-Location so Inertia performs a full-page navigation instead.
     */
    private function postLoginResponse(Request $request): Response
    {
        $handoff = $request->session()->pull('proxy_auth_handoff', false);
        $intended = $request->session()->pull('url.intended');

        if (is_string($intended) && $intended !== ''
            && ($handoff === true || $this->isCrossOriginUrl($intended, $request))) {
            return Inertia::location($intended);
        }

        return redirect()->to(is_string($intended) && $intended !== '' ? $intended : '/');
    }

    /**
     * Check whether a URL points to another host than the request's.
     */
    private function isCrossOriginUrl(string $url, Request $request): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return $host !== null && strtolower($host) !== strtolower($request->getHost());
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request): RedirectResponse
    {
        // Revoke reverse proxy session tokens so the user loses access to
        // login-protected proxy apps together with the NAS session.
        if (($user = $request->user()) !== null) {
            $this->proxyAuthService->revokeUserTokens($user);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
