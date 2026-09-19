<?php

namespace App\Services;

use App\Models\ProxyAuthToken;
use App\Models\ProxyHost;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * Proxy Auth Service
 *
 * Implements "require NAS login" for reverse proxy hosts. Apache checks a
 * session token cookie against a per-host RewriteMap token file; this service
 * mints, revokes, prunes those tokens and keeps the map files up to date.
 */
class ProxyAuthService
{
    private const AUTH_MAP_DIR = '/etc/apache2/novanas-proxy-auth';

    public const COOKIE_NAME = 'novanas_proxy_auth';

    public const BRIDGE_PATH = '/__novanas_proxy_auth';

    public const TOKEN_TTL_DAYS = 30;

    public const GRANT_TTL_SECONDS = 120;

    /**
     * Create a new service instance.
     */
    public function __construct(private SslService $sslService) {}

    /**
     * Get the RewriteMap token file path for a host.
     */
    public function authMapPath(ProxyHost $host): string
    {
        return self::AUTH_MAP_DIR.'/host-'.$host->id.'.txt';
    }

    /**
     * Make sure the host's RewriteMap file exists (Apache fails to load a
     * vhost that references a missing txt map).
     */
    public function ensureAuthMapFile(ProxyHost $host): void
    {
        Process::run(['sudo', 'mkdir', '-p', self::AUTH_MAP_DIR]);

        if (Process::run(['test', '-f', $this->authMapPath($host)])->successful()) {
            return;
        }

        $this->writeMapFile($this->authMapPath($host), '');
    }

    /**
     * Rewrite the host's token map with all valid tokens of its allowed users.
     */
    public function writeAuthMapFile(ProxyHost $host): void
    {
        if (! $host->auth_enabled) {
            return;
        }

        $lines = ProxyAuthToken::query()
            ->valid()
            ->whereIn('user_id', $host->authUsers()->pluck('users.id'))
            ->pluck('token_hash')
            ->map(fn (string $hash) => $hash.' 1');

        $content = $lines->isEmpty() ? '' : $lines->implode("\n")."\n";

        $this->ensureAuthMapFile($host);
        $this->writeMapFile($this->authMapPath($host), $content);
    }

    /**
     * Rewrite the token maps of every login-protected host.
     */
    public function writeAllAuthMaps(): void
    {
        ProxyHost::query()
            ->where('auth_enabled', true)
            ->get()
            ->each(fn (ProxyHost $host) => $this->writeAuthMapFile($host));
    }

    /**
     * Remove the host's token map file.
     */
    public function removeAuthMapFile(ProxyHost $host): void
    {
        Process::run(['sudo', 'rm', '-f', $this->authMapPath($host)]);
    }

    /**
     * The map file contains the cookie capabilities (token hashes); it must
     * be readable by the Apache worker processes only, not by every local
     * user. The worker group differs between installs, so it is read from
     * Apache's own environment.
     */
    private function restrictMapFilePermissions(string $path): void
    {
        $escaped = escapeshellarg($path);

        Process::run([
            'sudo', 'bash', '-c',
            'source /etc/apache2/envvars && chown "root:${APACHE_RUN_GROUP}" '.$escaped.' && chmod 640 '.$escaped,
        ]);
    }

    /**
     * Issue (or replace) the session token of a NAS user. The token hash
     * immediately becomes the cookie capability (via the Apache map file),
     * while the returned one-time nonce is the ONLY way a browser may obtain
     * the cookie: the grant URL carries the nonce, never the capability.
     *
     * @return array{hash: string, nonce: string}
     */
    public function issueToken(User $user, ?string $sessionId = null): array
    {
        ProxyAuthToken::query()->where('user_id', $user->id)->delete();

        $hash = hash('sha256', bin2hex(random_bytes(32)));
        $nonce = bin2hex(random_bytes(32));

        ProxyAuthToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => $hash,
            'session_id' => $sessionId,
            'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
            'grant_nonce' => $nonce,
            'grant_expires_at' => now()->addSeconds(self::GRANT_TTL_SECONDS),
        ]);

        $this->writeAllAuthMaps();

        return ['hash' => $hash, 'nonce' => $nonce];
    }

    /**
     * Redeem a one-time grant nonce and return its token record. The nonce
     * is consumed immediately, so an intercepted URL becomes useless after
     * the first redemption (or after GRANT_TTL_SECONDS).
     */
    public function redeemGrant(string $nonce): ?ProxyAuthToken
    {
        if ($nonce === '') {
            return null;
        }

        $token = ProxyAuthToken::query()
            ->where('grant_nonce', $nonce)
            ->where('grant_expires_at', '>', now())
            ->first();

        if ($token === null) {
            return null;
        }

        $token->forceFill(['grant_nonce' => null, 'grant_expires_at' => null])->save();

        return $token;
    }

    /**
     * Delete all tokens of a user (e.g. on NAS logout).
     */
    public function revokeUserTokens(User $user): void
    {
        $deleted = ProxyAuthToken::query()->where('user_id', $user->id)->delete();

        if ($deleted > 0) {
            $this->writeAllAuthMaps();
        }
    }

    /**
     * Delete expired tokens and tokens whose NAS session no longer exists.
     */
    public function pruneTokens(): void
    {
        $staleSessionIds = ProxyAuthToken::query()
            ->whereNotNull('session_id')
            ->where('created_at', '<', now()->subHour())
            ->pluck('session_id')
            ->reject(fn (string $sessionId) => DB::table('sessions')->where('id', $sessionId)->exists());

        $deleted = ProxyAuthToken::query()
            ->where(function ($query) use ($staleSessionIds) {
                $query->where('expires_at', '<=', now());

                if ($staleSessionIds->isNotEmpty()) {
                    $query->orWhereIn('session_id', $staleSessionIds->values()->all());
                }
            })
            ->delete();

        if ($deleted > 0) {
            $this->writeAllAuthMaps();
        }
    }

    /**
     * The scheme used to reach a proxy host publicly.
     */
    public function proxyHostScheme(ProxyHost $host): string
    {
        if ($host->ssl_mode === 'none') {
            return 'http';
        }

        $certExists = $this->sslService->certificateExists(
            ReverseProxyService::PROXY_CERT_BASE_DIR.'/'.$host->domain
        );

        return $certExists ? 'https' : 'http';
    }

    /**
     * Scheme and host under which the NAS login page is reachable.
     *
     * @return array{scheme: string, host: string}
     */
    public function nasBaseUrl(): array
    {
        return [
            'scheme' => $this->sslService->isSslEnabled() ? 'https' : 'http',
            'host' => $this->sslService->getCurrentHostname() ?? 'localhost',
        ];
    }

    /**
     * Parse a redirect target of the form "host/path?query" (as produced by
     * the Apache auth redirect).
     *
     * @return array{host: string, path: string, query: string}|null
     */
    public function parseRedirectTarget(string $target): ?array
    {
        $target = trim($target);

        if ($target === '' || preg_match('/[\s@]/', $target) === 1) {
            return null;
        }

        if (! preg_match('#^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(/.*)?$#', $target)) {
            return null;
        }

        $parts = parse_url('//'.$target);

        if (! isset($parts['host'])) {
            return null;
        }

        return [
            'host' => strtolower($parts['host']),
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? '',
        ];
    }

    /**
     * Validate an absolute URL against the login-protected proxy hosts and
     * return it when it is a safe redirect target for the NAS login page.
     */
    public function resolveSafeIntendedUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/')) {
            return null;
        }

        $exists = ProxyHost::query()
            ->where('domain', strtolower($parts['host']))
            ->where('auth_enabled', true)
            ->exists();

        if (! $exists) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Write content to a map file (root-owned, readable by Apache only).
     */
    private function writeMapFile(string $path, string $content): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'proxyauth_');
        file_put_contents($tmpFile, $content);

        Process::run(['sudo', 'cp', $tmpFile, $path]);
        $this->restrictMapFilePermissions($path);

        unlink($tmpFile);
    }
}
