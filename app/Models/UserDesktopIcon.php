<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * User Desktop Icon Model
 *
 * Represents a user's desktop icon placement for a specific app.
 *
 * @property int $id
 * @property int $user_id
 * @property int $desktop_app_id
 * @property int $position_x
 * @property int $position_y
 * @property bool $is_visible_desktop
 * @property bool $is_visible_launcher
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class UserDesktopIcon extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'desktop_app_id',
        'position_x',
        'position_y',
        'is_visible_desktop',
        'is_visible_launcher',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_visible_desktop' => 'boolean',
            'is_visible_launcher' => 'boolean',
            'position_x' => 'integer',
            'position_y' => 'integer',
        ];
    }

    /**
     * Get the user that owns this desktop icon.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the desktop app associated with this icon.
     */
    public function desktopApp(): BelongsTo
    {
        return $this->belongsTo(DesktopApp::class);
    }
}
