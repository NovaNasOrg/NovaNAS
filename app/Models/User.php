<?php

namespace App\Models;

use App\Services\SambaService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Spatie\LaravelPasskeys\Models\Concerns\HasPasskeys;
use Spatie\LaravelPasskeys\Models\Concerns\InteractsWithPasskeys;
use Spatie\LaravelPasskeys\Models\Passkey;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property bool $is_admin
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $username
 * @property string|null $invitation_token
 * @property Carbon|null $invitation_expires_at
 * @property string $status
 * @property Carbon|null $password_set_at
 * @property string $file_manager_layout
 * @property bool $show_hidden_files
 * @property string|null $two_factor_secret
 * @property bool $two_factor_enabled
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, Passkey> $passkeys
 * @property-read int|null $passkeys_count
 * @property-read Collection<int, DesktopDevice> $desktopDevices
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User active()
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User pending()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFileManagerLayout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereInvitationExpiresAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereInvitationToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsAdmin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePasswordSetAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereShowHiddenFiles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTwoFactorSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUsername($value)
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements HasPasskeys
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithPasskeys, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'invitation_token',
        'invitation_expires_at',
        'status',
        'password_set_at',
        'is_admin',
        'file_manager_layout',
        'show_hidden_files',
        'two_factor_secret',
        'two_factor_enabled',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token',
        'two_factor_secret',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'invitation_expires_at' => 'datetime',
            'password_set_at' => 'datetime',
            'is_admin' => 'boolean',
            'show_hidden_files' => 'boolean',
            'two_factor_enabled' => 'boolean',
        ];
    }

    /**
     * Store the plain password before hashing for Samba sync.
     * This attribute is not persisted to database.
     */
    protected ?string $plainPasswordForSamba = null;

    /**
     * Set the user's password attribute.
     *
     * This mutator captures and hashes the plain password, storing it in a
     * temporary attribute for Samba sync before the hashed version is saved.
     */
    public function setPasswordAttribute(mixed $value): void
    {
        // Store the plain password before hashing for Samba sync
        if ($value !== null) {
            $this->plainPasswordForSamba = $value;
            // Hash the password ourselves instead of relying on the 'hashed' cast
            // This ensures it works correctly with update() method
            $this->attributes['password'] = Hash::make($value);
        } else {
            $this->attributes['password'] = null;
        }
    }

    /**
     * Get the plain password stored for Samba sync.
     */
    public function getPlainPasswordForSamba(): ?string
    {
        return $this->plainPasswordForSamba;
    }

    /**
     * The "booted" method of the model.
     * Syncs password to Samba when password is changed.
     */
    protected static function booted(): void
    {
        // After creation, sync to Samba
        static::created(function (User $user) {
            // Use the plain password captured by the setter
            $plainPassword = $user->getPlainPasswordForSamba();
            if ($plainPassword && $user->username) {
                try {
                    $samba = app(SambaService::class);
                    $samba->updatePassword($user->username, $plainPassword);
                    \Log::info("Samba password synced successfully for user: {$user->username}");
                } catch (\RuntimeException $e) {
                    \Log::warning("Failed to sync password to Samba: {$e->getMessage()}");
                }
            }
            // Clear the plain password after sync
            $user->plainPasswordForSamba = null;
        });

        // After update, sync to Samba
        static::updated(function (User $user) {
            // Use the plain password captured by the setter
            $plainPassword = $user->getPlainPasswordForSamba();
            if ($plainPassword && $user->username && $user->wasChanged('password')) {
                try {
                    $samba = app(SambaService::class);
                    $samba->updatePassword($user->username, $plainPassword);
                    \Log::info("Samba password synced successfully for user: {$user->username}");
                } catch (\RuntimeException $e) {
                    \Log::warning("Failed to sync password to Samba: {$e->getMessage()}");
                }
            }
            // Clear the plain password after sync
            $user->plainPasswordForSamba = null;
        });
    }

    /**
     * @return HasMany<DesktopDevice, $this>
     */
    public function desktopDevices(): HasMany
    {
        return $this->hasMany(DesktopDevice::class);
    }

    /**
     * Scope a query to only include pending (invited) users.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope a query to only include active users.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if the user is pending (invited but hasn't set password).
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Check if the invitation has expired.
     */
    public function isInvitationExpired(): bool
    {
        if (! $this->invitation_expires_at) {
            return false;
        }

        return $this->invitation_expires_at->isPast();
    }

    /**
     * Check if the user can set their password (valid invitation).
     */
    public function canSetPassword(): bool
    {
        return $this->isPending() && ! $this->isInvitationExpired();
    }
}
