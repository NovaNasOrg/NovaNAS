<?php

namespace App\Services;

use App\Models\ProxyHost;
use Illuminate\Support\Facades\Process;

/**
 * Reverse Proxy Service
 *
 * Manages Apache reverse-proxy virtual hosts for arbitrary domains.
 * Each host gets its own Apache config file in sites-enabled and optionally
 * its own SSL certificate directory under /etc/ssl/novanas/proxy.
 */
class ReverseProxyService
{
    private const SITES_ENABLED_DIR = '/etc/apache2/sites-enabled';

    /**
     * Prefix for proxy vhost files. Files in sites-enabled are included
     * alphabetically and the first *:443 vhost becomes the default server
     * for requests without a matching SNI (e.g. IP access to the NAS UI),
     * so proxy files must sort AFTER the NAS's own vhosts.
     */
    private const CONFIG_PREFIX = 'zz-novanas-proxy-';

    private const LOG_PREFIX = 'novanas-proxy-';

    public const PROXY_CERT_BASE_DIR = '/etc/ssl/novanas/proxy';

    /**
     * Create a new service instance.
     */
    public function __construct(private SslService $sslService, private NovaNasApiService $novaNasApiService) {}

    /**
     * Get all proxy hosts with their certificate information.
     *
     * @return list<array<string, mixed>>
     */
    public function getHosts(): array
    {
        return ProxyHost::query()
            ->orderBy('domain')
            ->get()
            ->map(function (ProxyHost $host) {
                $data = $host->toArray();
                $data['certificate'] = $this->getHostCertificate($host);

                return $data;
            })
            ->all();
    }

    /**
     * Create a new proxy host and write its Apache config.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string, status: int, host?: ProxyHost}
     */
    public function createHost(array $data): array
    {
        $host = new ProxyHost;
        $host->fill($data);

        if ($host->https_redirect) {
            return [
                'success' => false,
                'message' => 'HTTPS redirect requires an active certificate for this host. Save the host first, then request or install a certificate.',
                'status' => 400,
            ];
        }

        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            // Roll back the row so no orphan proxy host remains
            $host->delete();

            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Proxy host created.',
            'status' => 201,
            'host' => $host->refresh(),
        ];
    }

    /**
     * Update a proxy host and rewrite its Apache config.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string, status: int, host?: ProxyHost}
     */
    public function updateHost(ProxyHost $host, array $data): array
    {
        $newDomain = $data['domain'] ?? $host->domain;

        if ($newDomain !== $host->domain && $this->hasActiveCertificate($host)) {
            return [
                'success' => false,
                'message' => 'Remove the certificate before changing the domain.',
                'status' => 400,
            ];
        }

        $original = $host->getOriginal();

        $host->fill($data);

        if ($host->https_redirect && ! $this->hasActiveCertificate($host)) {
            return [
                'success' => false,
                'message' => 'HTTPS redirect requires an active certificate for this host. Request or install a certificate first.',
                'status' => 400,
            ];
        }

        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            // The Apache config on disk was rolled back by writeApacheConfig,
            // so revert the database row to the original values as well.
            $host->fill($original);
            $host->save();

            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Proxy host updated.',
            'status' => 200,
            'host' => $host->refresh(),
        ];
    }

    /**
     * Delete a proxy host, its Apache config, and its certificate.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function deleteHost(ProxyHost $host): array
    {
        Process::run(['sudo', 'rm', '-f', $this->configPath($host)]);

        $this->sslService->removeCertificate($host->domain, $this->proxyCertDir($host->domain));

        $host->delete();

        $test = Process::run(['sudo', 'apache2ctl', 'configtest']);

        if ($test->failed()) {
            return [
                'success' => false,
                'message' => 'Apache config test failed: '.$test->errorOutput(),
                'status' => 500,
            ];
        }

        $reload = Process::run(['sudo', 'systemctl', 'reload', 'apache2']);

        if ($reload->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to reload Apache: '.$reload->errorOutput(),
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Proxy host deleted.',
            'status' => 200,
        ];
    }

    /**
     * Enable or disable a proxy host.
     *
     * @return array{success: bool, message: string, status: int, host?: ProxyHost}
     */
    public function toggleHost(ProxyHost $host, bool $enabled): array
    {
        $host->enabled = $enabled;
        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => $enabled ? 'Proxy host enabled.' : 'Proxy host disabled.',
            'status' => 200,
            'host' => $host->refresh(),
        ];
    }

    /**
     * Check whether the host has an active certificate
     * (a non-"none" SSL mode and existing certificate files).
     */
    public function hasActiveCertificate(ProxyHost $host): bool
    {
        if ($host->ssl_mode === 'none') {
            return false;
        }

        return $this->sslService->certificateExists($this->proxyCertDir($host->domain));
    }

    /**
     * Write (or remove, when disabled) the host's Apache virtual host config,
     * validate it with apache2ctl configtest, and reload Apache.
     *
     * On config test failure the previous config file is restored.
     *
     * @return array{success: bool, message: string}
     */
    public function writeApacheConfig(ProxyHost $host): array
    {
        $this->ensureApacheModules($host);

        $configPath = $this->configPath($host);

        if (! $host->enabled) {
            Process::run(['sudo', 'rm', '-f', $configPath]);

            $test = Process::run(['sudo', 'apache2ctl', 'configtest']);

            if ($test->failed()) {
                return [
                    'success' => false,
                    'message' => 'Apache config test failed: '.$test->errorOutput(),
                ];
            }

            $reload = Process::run(['sudo', 'systemctl', 'reload', 'apache2']);

            if ($reload->failed()) {
                return [
                    'success' => false,
                    'message' => 'Failed to reload Apache: '.$reload->errorOutput(),
                ];
            }

            return [
                'success' => true,
                'message' => 'Apache configuration updated.',
            ];
        }

        // Back up the existing config file content (null when none exists)
        $backup = null;
        $read = Process::run(['sudo', 'cat', $configPath]);

        if ($read->successful()) {
            $backup = $read->output();
        }

        $config = $this->generateVhostConfig($host);

        $tmpFile = tempnam(sys_get_temp_dir(), 'proxyconf_');
        file_put_contents($tmpFile, $config);

        $result = Process::run(['sudo', 'cp', $tmpFile, $configPath]);

        unlink($tmpFile);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to write Apache config: '.$result->errorOutput(),
            ];
        }

        $test = Process::run(['sudo', 'apache2ctl', 'configtest']);

        if ($test->failed()) {
            // Restore the previous config (or remove the new one)
            if ($backup !== null) {
                $tmpBackup = tempnam(sys_get_temp_dir(), 'proxyconf_');
                file_put_contents($tmpBackup, $backup);

                Process::run(['sudo', 'cp', $tmpBackup, $configPath]);

                unlink($tmpBackup);
            } else {
                Process::run(['sudo', 'rm', '-f', $configPath]);
            }

            return [
                'success' => false,
                'message' => 'Apache config test failed: '.$test->errorOutput(),
            ];
        }

        $reload = Process::run(['sudo', 'systemctl', 'reload', 'apache2']);

        if ($reload->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to reload Apache: '.$reload->errorOutput(),
            ];
        }

        return [
            'success' => true,
            'message' => 'Apache configuration updated.',
        ];
    }

    /**
     * Generate the Apache virtual host configuration for a proxy host.
     */
    public function generateVhostConfig(ProxyHost $host): string
    {
        $sslActive = $this->hasActiveCertificate($host);
        $certDir = $this->proxyCertDir($host->domain);
        $backend = $host->target_protocol.'://'.$host->target_host.':'.$host->target_port;
        $logPrefix = '${APACHE_LOG_DIR}/'.self::LOG_PREFIX.$host->id;

        // Strictly serve the configured hostname only. If Apache ever selects
        // this vhost as the default server (e.g. requests with another SNI
        // when no other vhost exists), any other Host header - direct IP
        // access or unassigned hostnames - is rejected instead of proxied.
        $hostGuard = '    RewriteEngine On'."\n"
            .'    RewriteCond %{HTTP_HOST} !^'.str_replace('.', '\.', $host->domain).'$ [NC]'."\n"
            .'    RewriteRule ^ - [F]'."\n"
            ."\n";

        $config = '# Managed by NovaNAS Reverse Proxy - do not edit manually'."\n"
            .'<VirtualHost *:80>'."\n"
            .'    ServerName '.$host->domain."\n"
            ."\n"
            .$hostGuard;

        // Port 80 vhost: either redirect to HTTPS or proxy over HTTP
        if ($sslActive && $host->https_redirect) {
            $config .= '    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/'."\n"
                .'    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]'."\n";
        } else {
            $config .= $this->generateProxyBlock($host, $backend, 'http');
        }

        $config .= '</VirtualHost>'."\n";

        // Port 443 vhost (only when a certificate is active)
        if ($sslActive) {
            $config .= "\n"
                .'<VirtualHost *:443>'."\n"
                .'    ServerName '.$host->domain."\n"
                ."\n"
                .'    SSLEngine on'."\n"
                .'    SSLCertificateFile '.$certDir.'/fullchain.pem'."\n"
                .'    SSLCertificateKeyFile '.$certDir.'/privkey.pem'."\n"
                ."\n";

            if ($host->target_protocol === 'https') {
                // Always accept self-signed / otherwise untrusted certificates on the backend
                $config .= '    SSLProxyEngine on'."\n"
                    .'    SSLProxyVerify none'."\n"
                    .'    SSLProxyCheckPeerCN off'."\n"
                    .'    SSLProxyCheckPeerName off'."\n"
                    .'    SSLProxyCheckPeerExpire off'."\n"
                    ."\n";
            }

            $config .= $hostGuard
                .$this->generateProxyBlock($host, $backend, 'https')
                .'    ErrorLog '.$logPrefix.'_error.log'."\n"
                .'    CustomLog '.$logPrefix.'_access.log combined'."\n"
                .'</VirtualHost>'."\n";
        }

        return $config;
    }

    /**
     * Generate the proxy directives for a virtual host.
     */
    private function generateProxyBlock(ProxyHost $host, string $backend, string $scheme): string
    {
        $block = '';

        if ($host->websocket_enabled) {
            $wsScheme = $host->target_protocol === 'https' ? 'wss' : 'ws';

            $block .= '    RewriteCond %{HTTP:Upgrade} =websocket [NC]'."\n"
                .'    RewriteRule /(.*) '.$wsScheme.'://'.$host->target_host.':'.$host->target_port.'/$1 [P,L]'."\n"
                ."\n";
        }

        $block .= '    ProxyPreserveHost On'."\n"
            .'    RequestHeader set X-Forwarded-Proto "'.$scheme.'"'."\n"
            .'    ProxyPass / '.$backend.'/'."\n"
            .'    ProxyPassReverse / '.$backend.'/'."\n";

        return $block;
    }

    /**
     * Issue a Let's Encrypt certificate for the host's domain and install it
     * into the host's certificate directory.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function issueLetsEncrypt(ProxyHost $host): array
    {
        if (! $host->enabled) {
            return [
                'success' => false,
                'message' => 'The proxy host must be enabled before requesting a Let\'s Encrypt certificate (the HTTP virtual host is required for the challenge).',
                'status' => 400,
            ];
        }

        $issueResult = $this->sslService->issueLetsEncrypt($host->domain);

        if (! $issueResult['success']) {
            return [
                'success' => false,
                'message' => $issueResult['message'],
                'status' => 500,
            ];
        }

        $installResult = $this->sslService->installCertificate(
            $host->domain,
            null,
            null,
            null,
            $this->proxyCertDir($host->domain)
        );

        if (! $installResult['success']) {
            return [
                'success' => false,
                'message' => $installResult['message'],
                'status' => 500,
            ];
        }

        $host->ssl_mode = 'letsencrypt';
        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Let\'s Encrypt certificate issued and installed.',
            'status' => 200,
        ];
    }

    /**
     * Generate a self-signed certificate for the host's domain.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function generateSelfSigned(ProxyHost $host): array
    {
        $result = $this->sslService->generateSelfSignedCertificate($host->domain, $this->proxyCertDir($host->domain));

        if (! $result['success']) {
            return [
                'success' => false,
                'message' => $result['message'],
                'status' => 500,
            ];
        }

        $host->ssl_mode = 'selfsigned';
        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Self-signed certificate generated and installed.',
            'status' => 200,
        ];
    }

    /**
     * Install a custom PEM certificate for the host's domain.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function installCustomCertificate(ProxyHost $host, string $cert, string $key, ?string $ca): array
    {
        $result = $this->sslService->installCertificate($host->domain, $cert, $key, $ca, $this->proxyCertDir($host->domain));

        if (! $result['success']) {
            return [
                'success' => false,
                'message' => $result['message'],
                'status' => 500,
            ];
        }

        $host->ssl_mode = 'custom';
        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Certificate installed.',
            'status' => 200,
        ];
    }

    /**
     * Remove the host's certificate and fall back to plain HTTP proxying.
     *
     * @return array{success: bool, message: string, status: int}
     */
    public function removeCertificate(ProxyHost $host): array
    {
        $this->sslService->removeCertificate($host->domain, $this->proxyCertDir($host->domain));

        $host->ssl_mode = 'none';
        $host->https_redirect = false;
        $host->save();

        $writeResult = $this->writeApacheConfig($host);

        if (! $writeResult['success']) {
            return [
                'success' => false,
                'message' => $writeResult['message'],
                'status' => 500,
            ];
        }

        return [
            'success' => true,
            'message' => 'Certificate removed. The host now proxies over HTTP.',
            'status' => 200,
        ];
    }

    /**
     * Check whether a domain is reachable from the internet.
     *
     * @return array{reachable: bool, ip?: string|null, message?: string|null}
     */
    public function checkDomainReachability(string $domain): array
    {
        return $this->novaNasApiService->checkReachability($domain);
    }

    /**
     * Get certificate information for a host.
     *
     * @return array{exists: bool, domain: string|null, issuer: string|null, expires_at: string|null}
     */
    private function getHostCertificate(ProxyHost $host): array
    {
        $info = $this->sslService->getCertificateInfo($this->proxyCertDir($host->domain));

        return [
            'exists' => $info !== null,
            'domain' => $info['domain'] ?? null,
            'issuer' => $info['issuer'] ?? null,
            'expires_at' => $info['expires_at'] ?? null,
        ];
    }

    /**
     * Enable the Apache modules required by the proxy config.
     */
    private function ensureApacheModules(ProxyHost $host): void
    {
        Process::run(['sudo', 'a2enmod', 'proxy', 'proxy_http', 'proxy_wstunnel', 'rewrite', 'headers']);

        if ($host->enabled && $this->hasActiveCertificate($host)) {
            Process::run(['sudo', 'a2enmod', 'ssl']);
        }
    }

    /**
     * Get the Apache config file path for a host.
     */
    private function configPath(ProxyHost $host): string
    {
        return self::SITES_ENABLED_DIR.'/'.self::CONFIG_PREFIX.$host->id.'.conf';
    }

    /**
     * Get the certificate directory for a host's domain.
     */
    private function proxyCertDir(string $domain): string
    {
        return self::PROXY_CERT_BASE_DIR.'/'.$domain;
    }
}
