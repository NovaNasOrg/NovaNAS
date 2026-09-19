<?php

use App\Models\ProxyAuthToken;
use App\Models\ProxyHost;
use App\Models\User;
use App\Services\ProxyAuthService;
use App\Services\ReverseProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['username' => 'admin']);
    $this->actingAs($this->user);
});

// ---------------------------------------------------------------------------
// Vhost config generation
// ---------------------------------------------------------------------------

test('login protection adds the auth directives to the vhost config', function () {
    Process::fake(['*' => Process::result()]);

    $host = ProxyHost::factory()->make([
        'domain' => 'app.example.com',
        'auth_enabled' => true,
    ]);
    $host->id = 5;

    $config = app(ReverseProxyService::class)->generateVhostConfig($host);

    expect($config)->toContain('ProxyPass /__novanas_proxy_auth !')
        ->toContain('RewriteMap novanas_proxy_auth_map_5 txt:/etc/apache2/novanas-proxy-auth/host-5.txt')
        // The bridge must be resolved to the front controller in the vhost
        // phase - the .htaccess front-controller rewrite would trigger an
        // internal redirect that re-runs these rules and bounces the grant
        // request back to the login
        ->toContain('RewriteRule ^\/__novanas_proxy_auth /index.php [L]')
        ->toContain('novanas_proxy_auth=([A-Fa-f0-9]{64})')
        ->toContain('${novanas_proxy_auth_map_5:%1|0} =1')
        // The redirect rule must consume the whole path (no remainder gets
        // appended to the substitution) and carry the original query via QSA
        ->toContain('RewriteRule ^ - [S=1]')
        ->toContain('proxy-auth/login?redirect=%{HTTP_HOST}%{REQUEST_URI} [NE,QSA,R=302,L]')
        ->not->toContain('${escape:')
        // The bridge must be served by the NAS app, not proxied to the backend
        ->toContain('DocumentRoot '.base_path('public'));
});

test('login protection is omitted when disabled', function () {
    Process::fake(['*' => Process::result()]);

    $host = ProxyHost::factory()->make([
        'domain' => 'app.example.com',
        'auth_enabled' => false,
    ]);
    $host->id = 5;

    $config = app(ReverseProxyService::class)->generateVhostConfig($host);

    expect($config)->not->toContain('RewriteMap')
        ->not->toContain('ProxyPass /__novanas_proxy_auth !')
        ->not->toContain('proxy-auth/login');
});

// ---------------------------------------------------------------------------
// API: allowed users
// ---------------------------------------------------------------------------

test('allowed users are persisted with the proxy host', function () {
    Process::fake(['*' => Process::result()]);

    $allowed = User::factory()->create();
    $host = ProxyHost::factory()->create(['domain' => 'app.example.com']);

    $response = $this->putJson('/api/reverse-proxy/hosts/'.$host->id, [
        'domain' => 'app.example.com',
        'target_protocol' => 'http',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'auth_enabled' => true,
        'auth_user_ids' => [$allowed->id, $this->user->id],
    ]);

    $response->assertStatus(200);
    expect($host->refresh()->authUsers->pluck('id')->sort()->values()->all())->toBe(
        collect([$allowed->id, $this->user->id])->sort()->values()->all()
    );
});

test('disabling login protection removes the allowed users', function () {
    Process::fake(['*' => Process::result()]);

    $host = ProxyHost::factory()->create(['domain' => 'app.example.com', 'auth_enabled' => true]);
    $host->authUsers()->attach($this->user);

    $response = $this->putJson('/api/reverse-proxy/hosts/'.$host->id, [
        'domain' => 'app.example.com',
        'target_protocol' => 'http',
        'target_host' => '192.168.1.50',
        'target_port' => 8096,
        'auth_enabled' => false,
        'auth_user_ids' => [$this->user->id],
    ]);

    $response->assertStatus(200);
    expect($host->refresh()->authUsers)->toBeEmpty();
});

test('the host list contains the allowed users', function () {
    Process::fake(['*' => Process::result()]);

    $host = ProxyHost::factory()->create(['domain' => 'app.example.com', 'auth_enabled' => true]);
    $host->authUsers()->attach($this->user);

    $response = $this->getJson('/api/reverse-proxy/hosts');

    $response->assertStatus(200)
        ->assertJsonPath('hosts.0.auth_enabled', true)
        ->assertJsonPath('hosts.0.auth_users.0.id', $this->user->id);
});

// ---------------------------------------------------------------------------
// NAS login bridge
// ---------------------------------------------------------------------------

function createProtectedHost(): ProxyHost
{
    return ProxyHost::factory()->create([
        'domain' => 'app.example.com',
        'auth_enabled' => true,
    ]);
}

test('the bridge redirects guests to the NAS login page', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    auth()->guard()->logout();

    $response = $this->get('/proxy-auth/login?redirect=app.example.com/jellyfin');

    $response->assertRedirect();
    $location = urldecode($response->headers->get('Location'));

    // The visitor is sent to the NAS login page and comes back to the bridge
    // after logging in (the original proxy URL is carried along).
    expect($location)->toContain('/login?proxy_redirect=')
        ->toContain('/proxy-auth/login')
        ->toContain('app.example.com');
});

test('the bridge redirects to the grant URL for an allowed user', function () {
    Process::fake(['*' => Process::result()]);

    $host = createProtectedHost();
    $host->authUsers()->attach($this->user);

    $response = $this->actingAs($this->user)->get('/proxy-auth/login?redirect=app.example.com/jellyfin');

    $response->assertRedirect();
    $location = $response->headers->get('Location');

    expect($location)->toContain('://app.example.com/__novanas_proxy_auth/grant?nonce=')
        ->toContain('redirect=%2Fjellyfin')
        // The cookie capability itself must never travel in the URL
        ->not->toContain('token=');

    $this->assertDatabaseHas('proxy_auth_tokens', ['user_id' => $this->user->id]);
});

test('the bridge re-attaches the original query parameters', function () {
    Process::fake(['*' => Process::result()]);

    $host = createProtectedHost();
    $host->authUsers()->attach($this->user);

    // Apache passes the original query as separate QSA parameters
    $response = $this->actingAs($this->user)
        ->get('/proxy-auth/login?redirect=app.example.com/jellyfin&foo=bar%20baz');

    $response->assertRedirect();

    expect($response->headers->get('Location'))->toContain('redirect=%2Fjellyfin%3Ffoo%3Dbar%2Bbaz');
});

test('the bridge denies access for users that are not allowed', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $response = $this->actingAs($this->user)->get('/proxy-auth/login?redirect=app.example.com/jellyfin');

    $response->assertStatus(403);
});

// ---------------------------------------------------------------------------
// Token grant (on the proxy domain)
// ---------------------------------------------------------------------------

test('a valid grant nonce sets the session cookie and redirects back', function () {
    Process::fake(['*' => Process::result()]);

    $host = createProtectedHost();
    $host->authUsers()->attach($this->user);

    $token = ProxyAuthToken::factory()->create([
        'user_id' => $this->user->id,
        'grant_nonce' => bin2hex(random_bytes(32)),
        'grant_expires_at' => now()->addMinutes(2),
    ]);

    $response = $this->get('http://app.example.com/__novanas_proxy_auth/grant?nonce='.$token->grant_nonce.'&redirect=%2Fjellyfin');

    $response->assertRedirect('/jellyfin');
    expect($response->headers->getCookies()[0]->getName())->toBe(ProxyAuthService::COOKIE_NAME)
        ->and($response->headers->getCookies()[0]->getValue())->toBe($token->token_hash)
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');

    // The nonce is single use
    expect($token->refresh()->grant_nonce)->toBeNull();
});

test('a grant nonce can only be redeemed once', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $token = ProxyAuthToken::factory()->create([
        'grant_nonce' => bin2hex(random_bytes(32)),
        'grant_expires_at' => now()->addMinutes(2),
    ]);

    $this->get('http://app.example.com/__novanas_proxy_auth/grant?nonce='.$token->grant_nonce.'&redirect=%2F');

    $second = $this->get('http://app.example.com/__novanas_proxy_auth/grant?nonce='.$token->grant_nonce.'&redirect=%2F');

    $second->assertRedirect();
    expect($second->headers->get('Location'))->toContain('/proxy-auth/login');
});

test('an expired grant nonce is rejected', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $token = ProxyAuthToken::factory()->create([
        'grant_nonce' => bin2hex(random_bytes(32)),
        'grant_expires_at' => now()->subSecond(),
    ]);

    $response = $this->get('http://app.example.com/__novanas_proxy_auth/grant?nonce='.$token->grant_nonce.'&redirect=%2F');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/proxy-auth/login');
});

test('a grant with an invalid nonce bounces back to the NAS bridge', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $response = $this->get('http://app.example.com/__novanas_proxy_auth/grant?nonce='.bin2hex(random_bytes(32)).'&redirect=%2Fjellyfin');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/proxy-auth/login?redirect=app.example.com');
});

// ---------------------------------------------------------------------------
// Token lifecycle
// ---------------------------------------------------------------------------

test('logging out of the NAS revokes the proxy token', function () {
    Process::fake(['*' => Process::result()]);

    $token = ProxyAuthToken::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post('/logout');

    $response->assertRedirect('/login');
    $this->assertDatabaseMissing('proxy_auth_tokens', ['id' => $token->id]);
});

test('pruning removes expired tokens and refreshes the maps', function () {
    Process::fake(['*' => Process::result()]);

    $host = createProtectedHost();
    $host->authUsers()->attach($this->user);

    ProxyAuthToken::factory()->create([
        'user_id' => $this->user->id,
        'expires_at' => now()->subDay(),
    ]);
    $alive = ProxyAuthToken::factory()->create(['user_id' => $this->user->id]);

    app(ProxyAuthService::class)->pruneTokens();

    $this->assertDatabaseMissing('proxy_auth_tokens', ['id' => $alive->id - 1]);
    $this->assertDatabaseHas('proxy_auth_tokens', ['id' => $alive->id]);
});

test('pruning removes tokens whose NAS session is gone', function () {
    Process::fake(['*' => Process::result()]);

    $host = createProtectedHost();
    $host->authUsers()->attach($this->user);

    $orphan = ProxyAuthToken::factory()->create([
        'user_id' => $this->user->id,
        'session_id' => 'deleted-session',
        'created_at' => now()->subHours(2),
    ]);

    app(ProxyAuthService::class)->pruneTokens();

    $this->assertDatabaseMissing('proxy_auth_tokens', ['id' => $orphan->id]);
});

test('the NAS login page honours a safe proxy redirect', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $response = $this->get('/login?proxy_redirect='.urlencode('https://app.example.com/__novanas_proxy_auth/login'));

    $response->assertStatus(200);
});

test('a login with a pending proxy redirect hands off via X-Inertia-Location', function () {
    Process::fake(['*' => Process::result()]);

    $user = User::factory()->create(['username' => 'proxyuser', 'password' => 'password']);

    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->withSession(['url.intended' => 'https://app.example.com/__novanas_proxy_auth/grant?nonce=abc'])
        ->postJson('/login', ['email' => $user->email, 'password' => 'password']);

    // Inertia XHR requests must not follow the cross-origin redirect chain
    // themselves (CORS) - the client performs a full-page navigation instead.
    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://app.example.com/__novanas_proxy_auth/grant?nonce=abc');
});

test('a login with a pending proxy redirect falls back to a plain redirect', function () {
    Process::fake(['*' => Process::result()]);

    $user = User::factory()->create(['username' => 'proxyuser2', 'password' => 'password']);

    $response = $this
        ->withSession(['url.intended' => 'https://app.example.com/__novanas_proxy_auth/grant?nonce=abc'])
        ->post('/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect('https://app.example.com/__novanas_proxy_auth/grant?nonce=abc');
});

test('a same-origin bridge redirect is handed off as a full-page navigation', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    $user = User::factory()->create(['username' => 'proxyuser3', 'password' => 'password']);

    // The bridge sets the intended URL to itself (same origin) plus the
    // handoff flag - the Inertia XHR must not follow the bridge's own
    // cross-origin redirect to the grant URL.
    $response = $this->withHeaders(['X-Inertia' => 'true'])
        ->withSession([
            'url.intended' => 'https://lelenaic.mynovanas.com/proxy-auth/login?redirect=desktop.lenaic.me%2F',
            'proxy_auth_handoff' => true,
        ])
        ->postJson('/login', ['email' => $user->email, 'password' => 'password']);

    $response->assertStatus(409)
        ->assertHeader('X-Inertia-Location', 'https://lelenaic.mynovanas.com/proxy-auth/login?redirect=desktop.lenaic.me%2F');
});

test('the NAS login page ignores unsafe proxy redirects', function () {
    Process::fake(['*' => Process::result()]);

    createProtectedHost();

    // A URL on a host without login protection must not become the redirect
    // target after login
    $service = app(ProxyAuthService::class);

    expect($service->resolveSafeIntendedUrl('https://evil.example.com/path'))->toBeNull()
        ->and($service->resolveSafeIntendedUrl('javascript:alert(1)'))->toBeNull();
});
