<?php
// app/Services/AuthService.php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /**
     * Waktu kadaluarsa token dalam menit.
     */
    private const TOKEN_EXPIRY_MINUTES = 60 * 24; // 24 jam

    /**
     * Entry point: login dengan identity (email atau nomor HP) + password.
     *
     * @return array{success: bool, message: string, data?: array, errors?: array}
     */
    public function login(string $identity, string $password): array
    {
        // 1. Tentukan apakah identity adalah email atau nomor HP
        $isEmail = str_contains($identity, '@');

        // 2. Temukan user berdasarkan identity
        $user = $isEmail
            ? $this->findByEmail($identity)
            : $this->findByPhone($identity);

        // 3. Validasi user & password
        if (! $user || ! Hash::check($password, $user->password)) {
            return [
                'success' => false,
                'message' => 'Invalid credentials',
                'errors'  => ['identity' => ['Email/nomor HP atau password salah.']],
            ];
        }

        // 4. Buat token Sanctum dengan expiration
        $expiresAt = now()->addMinutes(self::TOKEN_EXPIRY_MINUTES);
        $token     = $user->createToken(
            name: 'auth_token',
            expiresAt: $expiresAt
        )->plainTextToken;

        return [
            'success' => true,
            'message' => 'Login successful',
            'data'    => [
                'user'       => $this->formatUser($user),
                'token'      => $token,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toDateTimeString(),
            ],
        ];
    }

    /**
     * Cari user berdasarkan email.
     */
    private function findByEmail(string $email): ?User
    {
        return User::where('email', strtolower(trim($email)))->first();
    }

    /**
     * Cari user berdasarkan nomor HP (setelah normalisasi).
     */
    private function findByPhone(string $phone): ?User
    {
        $normalized = $this->normalizePhoneNumber($phone);

        // Coba cocokkan dengan format yang tersimpan di DB (+62xxx atau 08xxx)
        return User::where('phone_number', $normalized)
            ->orWhere('phone_number', $this->toLocalFormat($normalized))
            ->first();
    }

    /**
     * Normalisasi nomor HP ke format internasional (+62xxx).
     *
     * Contoh:
     *   '08123456789'  → '+628123456789'
     *   '628123456789' → '+628123456789'
     *   '+628123456789'→ '+628123456789'
     *   '8123456789'   → '+628123456789'
     */
    public function normalizePhoneNumber(string $phone): string
    {
        // Hapus semua karakter non-digit kecuali leading '+'
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        if (str_starts_with($cleaned, '+62')) {
            return $cleaned; // Sudah format internasional
        }

        if (str_starts_with($cleaned, '62')) {
            return '+' . $cleaned; // Tambah '+'
        }

        if (str_starts_with($cleaned, '0')) {
            return '+62' . substr($cleaned, 1); // Ganti '0' → '+62'
        }

        // Asumsi angka lokal tanpa prefix (misal: '8123456789')
        return '+62' . $cleaned;
    }

    /**
     * Konversi format +62 ke format lokal 08xxx (untuk matching fleksibel di DB).
     */
    private function toLocalFormat(string $internationalPhone): string
    {
        if (str_starts_with($internationalPhone, '+62')) {
            return '0' . substr($internationalPhone, 3);
        }

        return $internationalPhone;
    }

    /**
     * Format data user untuk response (hanya field yang aman).
     */
    private function formatUser(User $user): array
    {
        return [
            'id'           => $user->id,
            'name'         => $user->name,
            'email'        => $user->email,
            'phone_number' => $user->phone_number,
            'created_at'   => $user->created_at?->toDateTimeString(),
        ];
    }
}
