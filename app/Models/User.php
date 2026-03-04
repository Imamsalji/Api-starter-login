<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Carbon;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'status',
        'failed_attempts',
        'frozen_until',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'frozen_until'      => 'datetime',  // Auto-cast ke Carbon
        ];
    }

    // -----------------------------------------------------------------------
    // Helper Methods — dipakai oleh AuthService
    // -----------------------------------------------------------------------

    /**
     * Cek apakah akun sedang dalam status freeze (frozen_until masih di masa depan).
     */
    public function isFrozen(): bool
    {
        return $this->frozen_until !== null && $this->frozen_until->isFuture();
    }

    /**
     * Hitung sisa detik freeze (dibulatkan ke atas).
     */
    public function getFreezeRemainingSeconds(): int
    {
        if (! $this->isFrozen()) {
            return 0;
        }

        return (int) ceil(now()->diffInSeconds($this->frozen_until, absolute: false));
    }

    /**
     * Hitung sisa menit freeze untuk pesan yang lebih ramah.
     */
    public function getFreezeRemainingMinutes(): int
    {
        return (int) ceil($this->getFreezeRemainingSeconds() / 60);
    }

    /**
     * Cek apakah akun berstatus inactive.
     */
    public function isInactive(): bool
    {
        return $this->status === 'inactive';
    }
}
