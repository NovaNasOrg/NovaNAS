<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

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
            'password' => 'hashed',
            'invitation_expires_at' => 'datetime',
            'password_set_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
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
        if (!$this->invitation_expires_at) {
            return false;
        }

        return $this->invitation_expires_at->isPast();
    }

    /**
     * Check if the user can set their password (valid invitation).
     */
    public function canSetPassword(): bool
    {
        return $this->isPending() && !$this->isInvitationExpired();
    }
}
