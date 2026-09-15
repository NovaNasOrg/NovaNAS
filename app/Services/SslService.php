<?php

namespace App\Services;

use App\Models\DynDnsConfig;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * SSL Certificate Service
 *
 * Manages SSL certificates via acme.sh, including Let's Encrypt issuance
 * and custom certificate installation.
 */
class SslService
{
    private const ACME_SH = '/root/.acme.sh/acme.sh';

    private const CERT_DIR = '/etc/ssl/novanas';

    private const HTTPS_REDIRECT_START = '# NOVANAS HTTPS REDIRECT START';

    private const HTTPS_REDIRECT_END = '# NOVANAS HTTPS REDIRECT END';

    /**
     * Get the current SSL status.
     *
     * @return array{enabled: bool, certificate_exists: bool, certificate_info?: array{domain: string, issuer: string, expires_at: string}}
     */
    public function getSslStatus(): array
    {
        $sslEnabled = $this->isSslEnabled();
        $certExists = $this->certificateExists();
        $certInfo = $certExists ? $this->getCertificateInfo() : null;

        return [
            'enabled' => $sslEnabled,
            'certificate_exists' => $certExists,
            'certificate_info' => $certInfo,
        ];
    }

    /**
     * Get the current system hostname.
     */
    public function getCurrentHostname(): ?string
    {
        $hostname = gethostname();

        return $hostname ?: null;
    }

    /**
     * Find the DynDNS config whose full_domain matches the current hostname.
     */
    public function getHostnameDynDnsConfig(): ?DynDnsConfig
    {
        $hostname = $this->getCurrentHostname();

        if (! $hostname) {
            return null;
        }

        return DynDnsConfig::where('full_domain', $hostname)->first();
    }

    /**
     * Check if the current hostname is reachable from the internet.
     *
     * @return array{reachable: bool, ip?: string, message?: string}
     */
    public function checkReachability(): array
    {
        $hostname = $this->getCurrentHostname();

        if (! $hostname) {
            return [
                'reachable' => false,
                'message' => 'No hostname configured. Set a hostname in General settings or via DynDNS.',
            ];
        }

        $apiService = app(NovaNasApiService::class);

        return $apiService->checkReachability($hostname);
    }

    /**
     * Issue a Let's Encrypt certificate via acme.sh.
     *
     * @return array{success: bool, message: string, output?: string}
     */
    public function issueLetsEncrypt(string $domain): array
    {
        $result = Process::timeout(120)->run([
            'sudo', self::ACME_SH, '--issue', '--server', 'letsencrypt', '--apache', '-d', $domain, '--force',
        ]);

        if ($result->successful()) {
            Log::info('Let\'s Encrypt certificate issued', ['domain' => $domain]);

            return [
                'success' => true,
                'message' => 'Certificate issued successfully.',
                'output' => $result->output(),
            ];
        }

        Log::error('Let\'s Encrypt certificate issuance failed', [
            'domain' => $domain,
            'error' => $result->errorOutput(),
        ]);

        return [
            'success' => false,
            'message' => 'Failed to issue certificate: '.$result->errorOutput(),
        ];
    }

    /**
     * Install a certificate (Let's Encrypt or custom) via acme.sh --install-cert.
     *
     * For custom certificates, the cert and key files are written to acme.sh's
     * certificate directory first, then installed via --install-cert.
     *
     * @param  string  $domain  The domain name
     * @param  string|null  $certContent  PEM certificate content (for custom certs)
     * @param  string|null  $keyContent  PEM private key content (for custom certs)
     * @param  string|null  $caContent  PEM CA bundle content (for custom certs)
     * @param  string|null  $targetDir  Target directory for the certificate files (defaults to CERT_DIR)
     * @return array{success: bool, message: string}
     */
    public function installCertificate(string $domain, ?string $certContent = null, ?string $keyContent = null, ?string $caContent = null, ?string $targetDir = null): array
    {
        $this->ensureCertDir($targetDir);

        $targetDir ??= self::CERT_DIR;

        // For custom certificates, write files to acme.sh's storage directory
        if ($certContent !== null && $keyContent !== null) {
            $acmeDir = '/root/.acme.sh/'.$domain.'_ecc';

            $result = Process::run(['sudo', 'mkdir', '-p', $acmeDir]);

            if ($result->failed()) {
                return [
                    'success' => false,
                    'message' => 'Failed to create acme.sh directory: '.$result->errorOutput(),
                ];
            }

            // Write certificate file
            $this->writeAcmeFile($acmeDir.'/'.$domain.'.cer', $certContent);

            // Write key file
            $this->writeAcmeFile($acmeDir.'/'.$domain.'.key', $keyContent);

            // acme.sh --install-cert reads ca.cer and fullchain.cer from its
            // domain directory, so provide them ourselves. A self-managed
            // chain is the certificate plus the optional CA bundle.
            $this->writeAcmeFile($acmeDir.'/ca.cer', $caContent ?? $certContent);
            $this->writeAcmeFile($acmeDir.'/fullchain.cer', $caContent ? $certContent."\n".$caContent : $certContent);
        }

        $result = Process::timeout(60)->run([
            'sudo', self::ACME_SH, '--install-cert', '-d', $domain,
            '--cert-file', $targetDir.'/cert.pem',
            '--key-file', $targetDir.'/privkey.pem',
            '--fullchain-file', $targetDir.'/fullchain.pem',
            '--reloadcmd', 'service apache2 force-reload',
        ]);

        if ($result->successful()) {
            Log::info('Certificate installed', ['domain' => $domain]);

            return [
                'success' => true,
                'message' => 'Certificate installed successfully.',
            ];
        }

        Log::error('Certificate installation failed', [
            'domain' => $domain,
            'error' => $result->errorOutput(),
        ]);

        return [
            'success' => false,
            'message' => 'Failed to install certificate: '.$result->errorOutput(),
        ];
    }

    /**
     * Enable SSL on Apache.
     *
     * @return array{success: bool, message: string}
     */
    public function enableSsl(bool $forceHttps = false): array
    {
        // Enable mod_ssl
        $result = Process::run(['sudo', 'a2enmod', 'ssl']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to enable mod_ssl: '.$result->errorOutput(),
            ];
        }

        // Write SSL VirtualHost
        $publicPath = base_path('public');
        $vhConfig = '<VirtualHost *:443>'."\n"
            ."    DocumentRoot {$publicPath}"."\n"
            ."\n"
            .'    SSLEngine on'."\n"
            .'    SSLCertificateFile '.self::CERT_DIR.'/fullchain.pem'."\n"
            .'    SSLCertificateKeyFile '.self::CERT_DIR.'/privkey.pem'."\n"
            ."\n"
            ."    <Directory {$publicPath}>"."\n"
            .'        Options Indexes FollowSymLinks'."\n"
            .'        AllowOverride All'."\n"
            .'        Require all granted'."\n"
            .'    </Directory>'."\n"
            ."\n"
            .'    ErrorLog ${APACHE_LOG_DIR}/novanas_ssl_error.log'."\n"
            .'    CustomLog ${APACHE_LOG_DIR}/novanas_ssl_access.log combined'."\n"
            .'</VirtualHost>';

        $configPath = '/etc/apache2/sites-enabled/novanas-ssl.conf';

        $result = Process::run(['sudo', 'bash', '-c', "cat > {$configPath} <<'VHEOF'"."\n".$vhConfig."\n".'VHEOF']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to write SSL config: '.$result->errorOutput(),
            ];
        }

        // Optionally force HTTP to HTTPS redirection on the port-80 vhost.
        $redirectResult = $this->setHttpsRedirect($forceHttps);

        if (! $redirectResult['success']) {
            return $redirectResult;
        }

        // Config test and reload
        $result = Process::run(['sudo', 'apache2ctl', 'configtest']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Apache config test failed: '.$result->errorOutput(),
            ];
        }

        $result = Process::run(['sudo', 'systemctl', 'reload', 'apache2']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to reload Apache: '.$result->errorOutput(),
            ];
        }

        Log::info('SSL enabled on Apache');

        return [
            'success' => true,
            'message' => 'SSL enabled successfully.',
        ];
    }

    /**
     * Disable SSL on Apache.
     *
     * @return array{success: bool, message: string}
     */
    public function disableSsl(): array
    {
        // Remove SSL VirtualHost
        $configPath = '/etc/apache2/sites-enabled/novanas-ssl.conf';

        Process::run(['sudo', 'rm', '-f', $configPath]);

        // Disable mod_ssl
        Process::run(['sudo', 'a2dismod', 'ssl']);

        // Remove the HTTP to HTTPS redirect
        $this->setHttpsRedirect(false);

        // Config test and reload
        $result = Process::run(['sudo', 'apache2ctl', 'configtest']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Apache config test failed: '.$result->errorOutput(),
            ];
        }

        $result = Process::run(['sudo', 'systemctl', 'reload', 'apache2']);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to reload Apache: '.$result->errorOutput(),
            ];
        }

        Log::info('SSL disabled on Apache');

        return [
            'success' => true,
            'message' => 'SSL disabled successfully.',
        ];
    }

    /**
     * Remove a certificate via acme.sh.
     *
     * When a target directory is given, only that directory and the domain's
     * metadata entry are removed. Otherwise the whole certificate directory
     * is removed (NAS hostname behavior).
     *
     * @return array{success: bool, message: string}
     */
    public function removeCertificate(string $domain, ?string $targetDir = null): array
    {
        Process::run([
            'sudo', self::ACME_SH, '--remove', '-d', $domain, '--force',
        ]);

        if ($targetDir !== null) {
            Process::run(['sudo', 'rm', '-rf', $targetDir]);

            $this->removeCertificateMetadata($domain);
        } else {
            // Remove cert files from our directory
            Process::run(['sudo', 'rm', '-rf', self::CERT_DIR]);
        }

        Log::info('Certificate removed', ['domain' => $domain]);

        return [
            'success' => true,
            'message' => 'Certificate removed successfully.',
        ];
    }

    /**
     * Generate a self-signed certificate using openssl and install it via acme.sh.
     *
     * @param  string  $domain  The domain name
     * @param  string|null  $targetDir  Target directory for the certificate files (defaults to CERT_DIR)
     * @return array{success: bool, message: string}
     */
    public function generateSelfSignedCertificate(string $domain, ?string $targetDir = null): array
    {
        $this->ensureCertDir($targetDir);

        $acmeDir = '/root/.acme.sh/'.$domain.'_ecc';

        // Create acme.sh directory
        $result = Process::run(['sudo', 'mkdir', '-p', $acmeDir]);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to create certificate directory: '.$result->errorOutput(),
            ];
        }

        // Generate self-signed certificate valid for 90 days (3 months)
        $result = Process::timeout(60)->run([
            'sudo', 'openssl', 'req', '-x509', '-nodes', '-days', '90',
            '-newkey', 'rsa:2048',
            '-keyout', $acmeDir.'/'.$domain.'.key',
            '-out', $acmeDir.'/'.$domain.'.cer',
            '-subj', '/CN='.$domain.'/O=NovaNAS/C=US',
        ]);

        if ($result->failed()) {
            Log::error('Self-signed certificate generation failed', [
                'domain' => $domain,
                'error' => $result->errorOutput(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to generate certificate: '.$result->errorOutput(),
            ];
        }

        // acme.sh --install-cert reads fullchain.cer and ca.cer from its domain
        // directory. A self-signed certificate is its own chain, so provide them.
        $certFile = $acmeDir.'/'.$domain.'.cer';

        foreach (['fullchain.cer', 'ca.cer'] as $acmeFile) {
            $result = Process::run(['sudo', 'cp', $certFile, $acmeDir.'/'.$acmeFile]);

            if ($result->failed()) {
                return [
                    'success' => false,
                    'message' => 'Failed to prepare acme.sh certificate files: '.$result->errorOutput(),
                ];
            }
        }

        // Install the certificate via acme.sh --install-cert
        $installResult = $this->installCertificate($domain, null, null, null, $targetDir);

        if (! $installResult['success']) {
            return $installResult;
        }

        // Save metadata for renewal tracking
        $this->saveCertificateMetadata($domain, 'self-signed', $targetDir);

        Log::info('Self-signed certificate generated and installed', ['domain' => $domain]);

        return [
            'success' => true,
            'message' => 'Self-signed certificate generated and installed successfully.',
        ];
    }

    /**
     * Get all self-signed certificates that need renewal.
     *
     * @return array<int, array{domain: string, expires_at: string}>
     */
    public function getSelfSignedCertificatesNeedingRenewal(): array
    {
        $metadata = $this->readCertificateMetadata();

        if ($metadata === []) {
            return [];
        }

        $certsNeedingRenewal = [];
        $renewalThreshold = now()->addMonth();

        foreach ($metadata as $domain => $info) {
            if (($info['type'] ?? '') !== 'self-signed') {
                continue;
            }

            $expiresAt = $info['expires_at'] ?? null;

            if (! $expiresAt) {
                // Can't determine expiry, renew to be safe
                $certsNeedingRenewal[] = [
                    'domain' => $domain,
                    'expires_at' => 'unknown',
                ];

                continue;
            }

            $expiryDate = Carbon::parse($expiresAt);

            if ($expiryDate->lte($renewalThreshold)) {
                $certsNeedingRenewal[] = [
                    'domain' => $domain,
                    'expires_at' => $expiresAt,
                ];
            }
        }

        return $certsNeedingRenewal;
    }

    /**
     * Renew a self-signed certificate.
     *
     * @return array{success: bool, message: string}
     */
    public function renewSelfSignedCertificate(string $domain): array
    {
        Log::info('Renewing self-signed certificate', ['domain' => $domain]);

        return $this->generateSelfSignedCertificate($domain, $this->getCertificateDirFromMetadata($domain));
    }

    /**
     * Get the stored certificate directory for a domain from the metadata file.
     */
    private function getCertificateDirFromMetadata(string $domain): ?string
    {
        $metadata = $this->readCertificateMetadata();

        $certDir = $metadata[$domain]['cert_dir'] ?? null;

        return is_string($certDir) && $certDir !== '' ? $certDir : null;
    }

    /**
     * Read the certificate metadata file. The file is root-owned (written
     * via sudo), so it must be read through sudo as well.
     *
     * @return array<string, array<string, mixed>>
     */
    private function readCertificateMetadata(): array
    {
        $metadataFile = self::CERT_DIR.'/metadata.json';

        if (! file_exists($metadataFile)) {
            return [];
        }

        $result = Process::run(['sudo', 'cat', $metadataFile]);

        if ($result->failed()) {
            return [];
        }

        $metadata = json_decode($result->output(), true);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * Save certificate metadata for tracking.
     *
     * @param  string  $domain  The domain name
     * @param  string  $type  The certificate type (e.g. "self-signed")
     * @param  string|null  $certDir  The directory the certificate files live in (null = CERT_DIR)
     */
    protected function saveCertificateMetadata(string $domain, string $type, ?string $certDir = null): void
    {
        $this->ensureCertDir();

        $metadataFile = self::CERT_DIR.'/metadata.json';
        $metadata = $this->readCertificateMetadata();

        // Get expiry date from the certificate
        $expiresAt = null;
        $certFile = ($certDir ?? self::CERT_DIR).'/fullchain.pem';

        $result = Process::run(['sudo', 'openssl', 'x509', '-in', $certFile, '-noout', '-enddate']);

        if ($result->successful()) {
            $output = trim($result->output());
            if (str_starts_with($output, 'notAfter=')) {
                $expiresAt = substr($output, 9);
            }
        }

        $metadata[$domain] = [
            'type' => $type,
            'cert_dir' => $certDir,
            'created_at' => now()->toIso8601String(),
            'expires_at' => $expiresAt,
        ];

        $tmpFile = tempnam(sys_get_temp_dir(), 'sslmeta_');
        file_put_contents($tmpFile, json_encode($metadata, JSON_PRETTY_PRINT));

        Process::run(['sudo', 'cp', $tmpFile, $metadataFile]);

        unlink($tmpFile);
    }

    /**
     * Remove a domain's metadata entry from the metadata file.
     */
    public function removeCertificateMetadata(string $domain): void
    {
        $metadata = $this->readCertificateMetadata();

        if (! array_key_exists($domain, $metadata)) {
            return;
        }

        unset($metadata[$domain]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'sslmeta_');
        file_put_contents($tmpFile, json_encode($metadata, JSON_PRETTY_PRINT));

        Process::run(['sudo', 'cp', $tmpFile, self::CERT_DIR.'/metadata.json']);

        unlink($tmpFile);
    }

    /**
     * Check if SSL is enabled in Apache.
     */
    protected function isSslEnabled(): bool
    {
        $result = Process::run(['apache2ctl', '-M']);

        return $result->successful() && str_contains($result->output(), 'ssl_module');
    }

    /**
     * Check if a certificate exists.
     *
     * @param  string|null  $dir  Directory to inspect (defaults to CERT_DIR)
     */
    public function certificateExists(?string $dir = null): bool
    {
        $result = Process::run(['test', '-f', ($dir ?? self::CERT_DIR).'/fullchain.pem']);

        return $result->successful();
    }

    /**
     * Get certificate information.
     *
     * @param  string|null  $dir  Directory to inspect (defaults to CERT_DIR)
     * @return array{domain: string, issuer: string, expires_at: string}|null
     */
    public function getCertificateInfo(?string $dir = null): ?array
    {
        $certFile = ($dir ?? self::CERT_DIR).'/fullchain.pem';

        $result = Process::run(['sudo', 'openssl', 'x509', '-in', $certFile, '-noout', '-subject', '-issuer', '-enddate']);

        if ($result->failed()) {
            return null;
        }

        $output = $result->output();
        $domain = '';
        $issuer = '';
        $expiresAt = '';

        foreach (explode("\n", $output) as $line) {
            if (str_starts_with($line, 'subject=')) {
                preg_match('/CN\s*=\s*(.+)/', $line, $matches);
                $domain = $matches[1] ?? '';
            } elseif (str_starts_with($line, 'issuer=')) {
                preg_match('/CN\s*=\s*(.+)/', $line, $matches);
                $issuer = $matches[1] ?? '';
            } elseif (str_starts_with($line, 'notAfter=')) {
                $expiresAt = substr($line, 9);
            }
        }

        return [
            'domain' => $domain,
            'issuer' => $issuer,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Ensure the SSL certificate directory exists.
     *
     * @param  string|null  $dir  Directory to create (defaults to CERT_DIR)
     */
    protected function ensureCertDir(?string $dir = null): void
    {
        Process::run(['sudo', 'mkdir', '-p', $dir ?? self::CERT_DIR]);
    }

    /**
     * Write a file to the acme.sh directory via sudo.
     */
    protected function writeAcmeFile(string $path, string $content): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'acme_');
        file_put_contents($tmpFile, $content);

        Process::run(['sudo', 'cp', $tmpFile, $path]);

        unlink($tmpFile);
    }

    /**
     * Add or remove the HTTP to HTTPS redirect on the port-80 vhost.
     *
     * @return array{success: bool, message: string}
     */
    protected function setHttpsRedirect(bool $force): array
    {
        $configPath = '/etc/apache2/sites-enabled/000-default.conf';

        $result = Process::run(['sudo', 'cat', $configPath]);

        if ($result->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to read Apache port-80 config: '.$result->errorOutput(),
            ];
        }

        $config = $result->output();
        $block = self::HTTPS_REDIRECT_START."\n"
            .'    RewriteEngine On'."\n"
            .'    RewriteCond %{HTTPS} off'."\n"
            .'    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/'."\n"
            .'    RewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]'."\n"
            .'    '.self::HTTPS_REDIRECT_END;

        $hasRedirect = str_contains($config, self::HTTPS_REDIRECT_START);

        if ($force && ! $hasRedirect) {
            $insert = self::HTTPS_REDIRECT_START;
            $vhostClose = '</VirtualHost>';

            $config = str_replace($vhostClose, $block."\n".$vhostClose, $config);

            return $this->writeApacheConfig($configPath, $config);
        }

        if (! $force && $hasRedirect) {
            $start = self::HTTPS_REDIRECT_START;
            $end = self::HTTPS_REDIRECT_END;
            $pattern = '/\s*'.preg_quote($start, '/').'.*?'.preg_quote($end, '/').'\s*/s';
            $config = preg_replace($pattern, '', $config, 1) ?? $config;

            return $this->writeApacheConfig($configPath, $config);
        }

        return [
            'success' => true,
            'message' => 'Redirect configuration unchanged.',
        ];
    }

    /**
     * Persist an Apache config file and run a config test.
     *
     * @return array{success: bool, message: string}
     */
    protected function writeApacheConfig(string $configPath, string $config): array
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'apachecfg_');
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
}
