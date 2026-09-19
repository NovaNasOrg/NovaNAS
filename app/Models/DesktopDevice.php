<?php

namespace App\Models;

use Database\Factories\DesktopDeviceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $platform
 * @property string $samba_username
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property-read User $user
 */
class DesktopDevice extends Model
{
    /** @use HasFactory<DesktopDeviceFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'platform',
        'samba_username',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }
}
