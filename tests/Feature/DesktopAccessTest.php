<?php

use App\Models\User;
use App\Services\AclService;
use App\Services\DesktopAccessService;
use App\Services\LinuxUserService;
use App\Services\SambaService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('desktop folder access always reflects the current NovaNAS user permissions', function () {
    $user = User::factory()->create();
    $user->update(['username' => 'alice']);

    $share = [
        'name' => 'documents',
        'type' => 'custom',
        'comment' => 'Team documents',
        'path' => '/srv/documents',
        'guest' => 'no',
        'enabled' => true,
    ];

    $samba = Mockery::mock(SambaService::class);
    $samba->shouldReceive('getShares')->twice()->andReturn([$share]);

    $acl = Mockery::mock(AclService::class);
    $acl->shouldReceive('getPermissions')->once()->with('/srv/documents')->andReturn(['alice' => 'readwrite']);
    $acl->shouldReceive('getPermissions')->once()->with('/srv/documents')->andReturn([]);

    $linuxUsers = Mockery::mock(LinuxUserService::class);
    $service = new DesktopAccessService($samba, $acl, $linuxUsers);

    expect($service->foldersForUser($user))
        ->toHaveCount(1)
        ->and($service->foldersForUser($user))
        ->toBeEmpty();
});

test('generated desktop endpoints authenticate the device but perform file operations as the NovaNAS user', function () {
    $service = new DesktopAccessService(
        Mockery::mock(SambaService::class),
        Mockery::mock(AclService::class),
        Mockery::mock(LinuxUserService::class),
    );

    $configuration = $service->renderConfiguration([[
        'alias' => 'nvd-u7-aabbccddeeff',
        'path' => '/srv/documents',
        'username' => 'alice',
        'device_usernames' => ['nvd7abcdefghijk'],
        'read_only' => true,
    ]]);

    expect($configuration)
        ->toContain('[nvd-u7-aabbccddeeff]')
        ->toContain('valid users = nvd7abcdefghijk')
        ->toContain('force user = alice')
        ->toContain('read only = yes')
        ->toContain('browseable = no');
});

test('device identities cannot receive an automatic personal folder', function () {
    $service = new DesktopAccessService(
        Mockery::mock(SambaService::class),
        Mockery::mock(AclService::class),
        Mockery::mock(LinuxUserService::class),
    );

    $configuration = $service->renderConfiguration([], ['nvd1abcdefghijk']);

    expect($configuration)
        ->toContain('[homes]')
        ->toContain('invalid users = nvd1abcdefghijk')
        ->toContain('access based share enum = yes');
});

test('a personal folder endpoint is visible under the linked Linux username', function () {
    $service = new DesktopAccessService(
        Mockery::mock(SambaService::class),
        Mockery::mock(AclService::class),
        Mockery::mock(LinuxUserService::class),
    );

    $configuration = $service->renderConfiguration([[
        'alias' => 'glados',
        'path' => '/home/glados',
        'username' => 'glados',
        'device_usernames' => ['nvd1abcdefghijk', 'glados'],
        'read_only' => false,
        'browseable' => true,
    ]]);

    expect($configuration)
        ->toContain('[glados]')
        ->toContain('path = /home/glados')
        ->toContain('valid users = nvd1abcdefghijk glados')
        ->toContain('force user = glados')
        ->toContain('browseable = yes');
});
