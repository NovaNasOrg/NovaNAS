<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Desktop Application Model
 *
 * Represents a desktop app that can be displayed on the user's desktop.
 *
 * @property int $id
 * @property string $identifier
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property string|null $url
 * @property string $icon_type
 * @property string|null $icon_name
 * @property string|null $icon_path
 * @property string $color
 * @property string|null $component_path
 * @property bool $is_system
 * @property bool $is_global
 * @property bool $is_admin_only
 * @property int|null $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class DesktopApp extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'identifier',
        'name',
        'description',
        'type',
        'url',
        'icon_type',
        'icon_name',
        'icon_path',
        'color',
        'component_path',
        'is_system',
        'is_global',
        'is_admin_only',
        'created_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_global' => 'boolean',
            'is_admin_only' => 'boolean',
        ];
    }

    /**
     * Get the user desktop icons for this app.
     */
    public function userDesktopIcons(): HasMany
    {
        return $this->hasMany(UserDesktopIcon::class);
    }

    /**
     * Get the user who created this app.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if this app can be deleted by a given user.
     */
    public function canBeDeletedBy(?User $user): bool
    {
        if ($this->is_system) {
            return false;
        }

        if ($this->is_global && !$this->isSystem()) {
            if (!$user || !$user->is_admin) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if this is a system app.
     */
    public function isSystem(): bool
    {
        return $this->is_system;
    }

    /**
     * Check if this is a global app (visible to all users).
     */
    public function isGlobal(): bool
    {
        return $this->is_global;
    }

    /**
     * Check if this app is only visible to admins.
     */
    public function isAdminOnly(): bool
    {
        return $this->is_admin_only;
    }

    /**
     * Check if this app is a component type.
     */
    public function isComponent(): bool
    {
        return $this->type === 'component';
    }

    /**
     * Check if this app is a URL type.
     */
    public function isUrl(): bool
    {
        return $this->type === 'url';
    }

    /**
     * Scope to include only visible apps for a specific user.
     */
    public function scopeVisibleFor($query, \App\Models\User $user)
    {
        return $query->where(function ($q) use ($user) {
            // Include global apps
            $q->where('is_global', true);

            // Include admin-only apps for admins
            if ($user->is_admin) {
                $q->orWhere('is_admin_only', true);
            }
        });
    }

    /**
     * Scope to include only component-type apps.
     */
    public function scopeComponents($query)
    {
        return $query->where('type', 'component');
    }

    /**
     * Scope to include apps with user icon positions.
     */
    public function scopeWithUserPosition($query, int $userId)
    {
        return $query->with(['userDesktopIcons' => function ($q) use ($userId) {
            $q->where('user_id', $userId);
        }]);
    }

    /**
     * Get user icon position for a specific user.
     */
    public function getUserPosition(int $userId): ?array
    {
        $userIcon = $this->userDesktopIcons->firstWhere('user_id', $userId);

        if ($userIcon) {
            return [
                'position_x' => $userIcon->position_x,
                'position_y' => $userIcon->position_y,
                'is_visible_desktop' => $userIcon->is_visible_desktop,
                'is_visible_launcher' => $userIcon->is_visible_launcher,
            ];
        }

        return null;
    }
}
