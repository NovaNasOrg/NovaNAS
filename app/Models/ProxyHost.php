<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reverse Proxy Host Model
 *
 * @property int $id
 * @property string $domain
 * @property string $target_host
 * @property int $target_port
 * @property string $target_protocol
 * @property bool $websocket_enabled
 * @property bool $https_redirect
 * @property string $ssl_mode
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereDomain($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereHttpsRedirect($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereSslMode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereTargetHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereTargetPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereTargetProtocol($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProxyHost whereWebsocketEnabled($value)
 *
 * @mixin \Eloquent
 */
class ProxyHost extends Model
{
    use HasFactory;

    protected $table = 'proxy_hosts';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'target_host',
        'target_port',
        'target_protocol',
        'websocket_enabled',
        'https_redirect',
        'ssl_mode',
        'enabled',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_port' => 'integer',
            'websocket_enabled' => 'boolean',
            'https_redirect' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
