<?php

namespace App\Models;

use Database\Factories\ProxyAuthTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proxy Auth Token Model
 *
 * One session token per NAS user that grants access to login-protected
 * reverse proxy hosts. The Apache RewriteMap files contain the token hash,
 * the plain token is only ever sent once (in the grant redirect URL).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property string|null $session_id
 * @property Carbon|null $expires_at
 * @property string|null $grant_nonce
 * @property Carbon|null $grant_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 *
 * @method static Builder<static>|ProxyAuthToken valid()
 * @method static \Database\Factories\ProxyAuthTokenFactory factory($count = null, $state = [])
 * @method static Builder<static>|ProxyAuthToken newModelQuery()
 * @method static Builder<static>|ProxyAuthToken newQuery()
 * @method static Builder<static>|ProxyAuthToken query()
 *
 * @mixin \Eloquent
 */
class ProxyAuthToken extends Model
{
    /** @use HasFactory<ProxyAuthTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token_hash',
        'session_id',
        'expires_at',
        'grant_nonce',
        'grant_expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'grant_expires_at' => 'datetime',
        ];
    }

    /**
     * The NAS user that owns the token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to tokens that are not expired.
     */
    public function scopeValid(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }
}
