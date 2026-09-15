<?php

use App\Models\DynDnsConfig;
use App\Models\ProxyHost;
use App\Models\User;
use App\Services\ReverseProxyService;
use App\Services\SslService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'admin']);
    $this->actingAs($this->user);
});

function proxyHostPayload(array $overrides = []): array
{
    return array_merge([
        'domain' => 'app.example.com',
        'target_protocol' => 'http',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'websocket_enabled' => false,
        'https_redirect' => false,
        'enabled' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

test('rejects the NAS hostname as a proxy domain', function () {
    $hostname = app(SslService::class)->getCurrentHostname();

    if ($hostname === null || $hostname === '') {
        $this->markTestSkipped('No system hostname available.');
    }

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload(['domain' => $hostname]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('domain');
});

test('rejects DynDNS managed domains as proxy domains', function () {
    DynDnsConfig::create([
        'provider' => 'duckdns',
        'name' => 'Home',
        'subdomain' => 'mynas',
        'token' => 'secret-token',
        'interval_minutes' => 5,
        'is_enabled' => true,
    ]);

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
        'domain' => 'mynas.duckdns.org',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('domain');
    $response->assertJsonFragment(['domain' => ['This domain is managed by DynDNS and points to this NAS.']]);
});

test('rejects raw IP addresses as proxy domains', function () {
    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
        'domain' => '192.168.1.50',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('domain');
});

test('rejects malformed domains as proxy domains', function () {
    foreach (['app..example.com', '-app.example.com', 'localhost', 'app.example.123', 'nodot'] as $domain) {
        $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
            'domain' => $domain,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('domain');
    }
});

test('rejects duplicate proxy host domains', function () {
    ProxyHost::factory()->create(['domain' => 'app.example.com']);

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
        'domain' => 'app.example.com',
    ]));

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('domain');
});

test('accepts a normal external domain', function () {
    Process::fake(['*' => Process::result()]);

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
        'domain' => 'app.example.com',
    ]));

    $response->assertStatus(201);
    $this->assertDatabaseHas('proxy_hosts', [
        'domain' => 'app.example.com',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
    ]);
});

// ---------------------------------------------------------------------------
// Service-level guards
// ---------------------------------------------------------------------------

test('rejects https redirect on a host without an active certificate', function () {
    Process::fake(['*' => Process::result()]);

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload([
        'https_redirect' => true,
    ]));

    $response->assertStatus(400);
    $this->assertDatabaseMissing('proxy_hosts', ['domain' => 'app.example.com']);
});

// ---------------------------------------------------------------------------
// Vhost config generation
// ---------------------------------------------------------------------------

test('vhosts only match the configured hostname', function () {
    Process::fake(['*' => Process::result()]);

    $service = app(ReverseProxyService::class);

    $host = ProxyHost::factory()->make([
        'domain' => 'app.example.com',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'target_protocol' => 'https',
        'ssl_mode' => 'custom',
    ]);
    $host->id = 11;

    $config = $service->generateVhostConfig($host);

    // Each vhost must reject every other Host header (IP access, unassigned hostnames)
    expect(substr_count($config, 'RewriteCond %{HTTP_HOST} !^app\.example\.com$ [NC]'))->toBe(2)
        ->and(substr_count($config, 'RewriteRule ^ - [F]'))->toBe(2);
});

test('websocket rewrite rules are only generated when enabled', function () {
    Process::fake([
        '*fullchain.pem*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    Process::preventStrayProcesses();

    $service = app(ReverseProxyService::class);

    $disabled = ProxyHost::factory()->make([
        'domain' => 'app.example.com',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'target_protocol' => 'http',
        'websocket_enabled' => false,
        'ssl_mode' => 'none',
    ]);
    $disabled->id = 7;

    $config = $service->generateVhostConfig($disabled);

    expect($config)->toContain('ProxyPass / http://192.168.1.50:8096/')
        ->not->toContain('ws://192.168.1.50:8096')
        ->not->toContain('%{HTTP:Upgrade}');

    $enabled = ProxyHost::factory()->make([
        'domain' => 'app.example.com',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'target_protocol' => 'http',
        'websocket_enabled' => true,
        'ssl_mode' => 'none',
    ]);
    $enabled->id = 7;

    $config = $service->generateVhostConfig($enabled);

    expect($config)->toContain('RewriteCond %{HTTP:Upgrade} =websocket [NC]')
        ->toContain('RewriteRule /(.*) ws://192.168.1.50:8096/$1 [P,L]');
});

test('ssl vhost is generated when a certificate is active', function () {
    // Certificate files "exist" (test -f succeeds)
    Process::fake(['*' => Process::result()]);

    $service = app(ReverseProxyService::class);

    $host = ProxyHost::factory()->make([
        'domain' => 'secure.example.com',
        'target_host' => '192.168.1.60',
        'target_port' => 443,
        'target_protocol' => 'https',
        'ssl_mode' => 'letsencrypt',
    ]);
    $host->id = 9;

    $config = $service->generateVhostConfig($host);

    expect($config)->toContain('<VirtualHost *:443>')
        ->toContain('SSLEngine on')
        ->toContain('/etc/ssl/novanas/proxy/secure.example.com/fullchain.pem')
        ->toContain('SSLProxyEngine on')
        ->toContain('SSLProxyVerify none')
        ->toContain('SSLProxyCheckPeerCN off')
        ->toContain('SSLProxyCheckPeerName off')
        ->toContain('SSLProxyCheckPeerExpire off')
        ->toContain('ProxyPass / https://192.168.1.60:443/')
        ->toContain('novanas-proxy-9_error.log');
});

test('ssl vhost falls back to http proxying when certificate files are missing', function () {
    Process::fake([
        '*fullchain.pem*' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);
    Process::preventStrayProcesses();

    $service = app(ReverseProxyService::class);

    $host = ProxyHost::factory()->make([
        'domain' => 'secure.example.com',
        'target_host' => '192.168.1.60',
        'target_port' => 443,
        'target_protocol' => 'https',
        'ssl_mode' => 'letsencrypt',
    ]);
    $host->id = 9;

    $config = $service->generateVhostConfig($host);

    expect($config)->not->toContain('<VirtualHost *:443>')
        ->toContain('ProxyPass / https://192.168.1.60:443/');
});

test('https redirect excludes the acme challenge path', function () {
    Process::fake(['*' => Process::result()]);

    $service = app(ReverseProxyService::class);

    $host = ProxyHost::factory()->make([
        'domain' => 'secure.example.com',
        'target_host' => '192.168.1.60',
        'target_port' => 8096,
        'target_protocol' => 'http',
        'ssl_mode' => 'letsencrypt',
        'https_redirect' => true,
    ]);
    $host->id = 9;

    $config = $service->generateVhostConfig($host);

    expect($config)->toContain('RewriteEngine On')
        ->toContain('RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/')
        ->toContain('RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]')
        ->toContain('X-Forwarded-Proto "https"');
});

// ---------------------------------------------------------------------------
// acme.sh install compatibility
// ---------------------------------------------------------------------------

test('self-signed generation provides fullchain and ca files for acme.sh install', function () {
    Process::fake(['*' => Process::result()]);

    app(SslService::class)->generateSelfSignedCertificate('app.example.com');

    $assertCopied = fn (string $file) => Process::assertRan(
        fn ($process, $result) => str_contains(
            implode(' ', (array) $process->command),
            'cp /root/.acme.sh/app.example.com_ecc/app.example.com.cer /root/.acme.sh/app.example.com_ecc/'.$file
        )
    );

    $assertCopied('fullchain.cer');
    $assertCopied('ca.cer');
});

test('custom certificate install provides fullchain and ca files for acme.sh install', function () {
    Process::fake(['*' => Process::result()]);

    app(SslService::class)->installCertificate('app.example.com', 'CERT-PEM', 'KEY-PEM', null);

    Process::assertRan(function ($process, $result) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'cp ')
            && str_contains($command, '/root/.acme.sh/app.example.com_ecc/fullchain.cer');
    });

    Process::assertRan(function ($process, $result) {
        $command = implode(' ', (array) $process->command);

        return str_contains($command, 'cp ')
            && str_contains($command, '/root/.acme.sh/app.example.com_ecc/ca.cer');
    });
});

// ---------------------------------------------------------------------------
// Feature behavior
// ---------------------------------------------------------------------------

test('creating a proxy host writes the apache config', function () {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload());

    $response->assertStatus(201);

    Process::assertRan(function ($process, $result) {
        return str_contains(implode(' ', (array) $process->command), 'configtest');
    });
});

test('creating a proxy host is rolled back when the apache config test fails', function () {
    Process::fake([
        '*configtest*' => Process::result(exitCode: 1, errorOutput: 'AH00526: Syntax error on line 3'),
        '*' => Process::result(),
    ]);

    $response = $this->postJson('/api/reverse-proxy/hosts', proxyHostPayload());

    $response->assertStatus(500);
    $this->assertDatabaseMissing('proxy_hosts', ['domain' => 'app.example.com']);
});

test('deleting a proxy host removes the config and certificate directory', function () {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    $host = ProxyHost::factory()->create([
        'domain' => 'app.example.com',
        'ssl_mode' => 'selfsigned',
    ]);

    $response = $this->deleteJson('/api/reverse-proxy/hosts/'.$host->id);

    $response->assertStatus(200);
    $this->assertDatabaseMissing('proxy_hosts', ['id' => $host->id]);

    $hostId = $host->id;

    Process::assertRan(
        fn ($process, $result) => str_contains(implode(' ', (array) $process->command), 'rm -f /etc/apache2/sites-enabled/zz-novanas-proxy-'.$hostId.'.conf')
    );

    Process::assertRan(
        fn ($process, $result) => str_contains(implode(' ', (array) $process->command), 'rm -rf /etc/ssl/novanas/proxy/app.example.com')
    );
});
