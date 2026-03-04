<?php
// app/Services/AuthService.php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    // -----------------------------------------------------------------------
    // Konstanta Freeze Tiers
    // -----------------------------------------------------------------------

    /**
     * Definisi tier freeze:
     * Key   = jumlah failed_attempts yang memicu freeze
     * Value = durasi freeze dalam menit (null = nonaktifkan akun permanen)
     *
     * Tier 1 → attempts ke-3  : freeze 3 menit
     * Tier 2 → attempts ke-6  : freeze 10 menit
     * Tier 3 → attempts ke-9  : status 'inactive' (permanent until admin reset)
     */
    private const FREEZE_TIERS = [
        3 => 3,    // 3 gagal → beku 3 menit
        6 => 10,   // 6 gagal → beku 10 menit
        9 => null, // 9 gagal → nonaktifkan akun
    ];

    /**
     * Durasi token dalam menit setelah login berhasil.
     */
    private const TOKEN_EXPIRY_MINUTES = 60 * 24; // 24 jam

    // -----------------------------------------------------------------------
    // Public Entry Point
    // -----------------------------------------------------------------------

    /**
     * Proses login dengan identity (email/HP) dan password.
     * Mengembalikan array terstruktur yang dikonsumsi oleh Controller.
     */
    public function login(string $identity, string $password): array
    {
        // --- Step 1: Temukan user berdasarkan identity ---
        $isEmail = str_contains($identity, '@');
        $user    = $isEmail
            ? $this->findByEmail($identity)
            : $this->findByPhone($identity);

        // Jika user tidak ditemukan, kembalikan error generik
        // (jangan beritahu apakah email/HP terdaftar atau tidak → security)
        if (! $user) {
            return $this->responseInvalidCredentials();
        }

        // --- Step 2: Cek status inactive SEBELUM apapun ---
        // Akun yang di-inactive oleh admin tidak boleh melewati tahap ini
        if ($user->isInactive()) {
            return $this->responseInactive();
        }

        // --- Step 3: Cek freeze aktif ---
        // Jika akun sedang di-freeze, tolak langsung tanpa memeriksa password
        if ($user->isFrozen()) {
            return $this->responseFrozen($user);
        }

        // --- Step 4: Verifikasi password ---
        if (! Hash::check($password, $user->password)) {
            // Password salah → proses logika freeze
            return $this->handleFailedAttempt($user);
        }

        // --- Step 5: Login berhasil → reset attempts & buat token ---
        return $this->handleSuccessfulLogin($user);
    }

    // -----------------------------------------------------------------------
    // Failed Attempt Handler — Inti Tiered Freeze Logic
    // -----------------------------------------------------------------------

    /**
     * Dipanggil setiap kali password salah.
     * Menaikkan counter dan menerapkan freeze tier yang sesuai.
     */
    private function handleFailedAttempt(User $user): array
    {
        // Naikkan jumlah percobaan gagal sebesar 1
        $newAttempts = $user->failed_attempts + 1;

        // Cek apakah attempts baru ini memicu salah satu tier freeze
        if (array_key_exists($newAttempts, self::FREEZE_TIERS)) {
            $freezeDuration = self::FREEZE_TIERS[$newAttempts];

            // --- Tier 3 (attempts ke-9): Nonaktifkan akun secara permanen ---
            if ($freezeDuration === null) {
                $user->update([
                    'failed_attempts' => $newAttempts,
                    'status'          => 'inactive',
                    'frozen_until'    => null, // Tidak perlu waktu freeze
                ]);

                return $this->responseInactive();
            }

            // --- Tier 1 (attempts ke-3) atau Tier 2 (attempts ke-6): Freeze sementara ---
            $frozenUntil = now()->addMinutes($freezeDuration);

            $user->update([
                'failed_attempts' => $newAttempts,
                'frozen_until'    => $frozenUntil,
            ]);

            // Re-load user agar helper method isFrozen() & getRemainingSeconds() akurat
            $user->refresh();

            return $this->responseFrozen($user, isNewFreeze: true);
        }

        // Belum mencapai tier manapun → simpan attempts saja
        $user->update(['failed_attempts' => $newAttempts]);

        return $this->responseInvalidCredentials($newAttempts);
    }

    // -----------------------------------------------------------------------
    // Successful Login Handler
    // -----------------------------------------------------------------------

    /**
     * Reset semua counter keamanan dan buat token Sanctum baru.
     */
    private function handleSuccessfulLogin(User $user): array
    {
        // Reset semua penanda keamanan karena login berhasil
        $user->update([
            'failed_attempts' => 0,
            'frozen_until'    => null,
        ]);

        // Buat token dengan expiration time
        $expiresAt     = now()->addMinutes(self::TOKEN_EXPIRY_MINUTES);
        $plainTextToken = $user->createToken(
            name: 'auth_token',
            expiresAt: $expiresAt
        )->plainTextToken;

        return [
            'success'     => true,
            'http_status' => 200,
            'message'     => 'Login successful',
            'data'        => [
                'user'       => $this->formatUser($user),
                'token'      => $plainTextToken,
                'token_type' => 'Bearer',
                'expires_at' => $expiresAt->toDateTimeString(),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Response Builders
    // -----------------------------------------------------------------------

    /**
     * Response ketika password salah namun belum mencapai tier freeze.
     * Sertakan sisa percobaan agar user tahu seberapa dekat dengan freeze.
     */
    private function responseInvalidCredentials(int $currentAttempts = 0): array
    {
        // Cari tier berikutnya untuk memberitahu user
        $nextTierAt    = $this->getNextFreezeTier($currentAttempts);
        $attemptsLeft  = $nextTierAt !== null ? ($nextTierAt - $currentAttempts) : null;

        $message = 'Invalid credentials.';
        if ($attemptsLeft !== null) {
            $message .= " Sisa {$attemptsLeft} percobaan sebelum akun dibekukan.";
        }

        return [
            'success'     => false,
            'http_status' => 401,
            'message'     => $message,
            'errors'      => ['identity' => ['Email/nomor HP atau password salah.']],
        ];
    }

    /**
     * Response ketika akun sedang di-freeze.
     * Selalu sertakan sisa waktu tunggu dalam detik dan menit.
     */
    private function responseFrozen(User $user, bool $isNewFreeze = false): array
    {
        $remainingSeconds = $user->getFreezeRemainingSeconds();
        $remainingMinutes = $user->getFreezeRemainingMinutes();

        $message = $isNewFreeze
            ? "Terlalu banyak percobaan gagal. Akun dibekukan selama {$remainingMinutes} menit."
            : "Akun sedang dibekukan. Silakan coba lagi dalam {$remainingMinutes} menit ({$remainingSeconds} detik).";

        return [
            'success'     => false,
            'http_status' => 423, // 423 Locked
            'message'     => $message,
            'errors'      => [
                'account' => [
                    "Akun dibekukan hingga {$user->frozen_until->toDateTimeString()}.",
                ],
            ],
            'data' => [
                'frozen_until'      => $user->frozen_until->toDateTimeString(),
                'remaining_seconds' => $remainingSeconds,
                'remaining_minutes' => $remainingMinutes,
            ],
        ];
    }

    /**
     * Response ketika akun berstatus inactive (baik dari freeze tier-3 maupun admin).
     */
    private function responseInactive(): array
    {
        return [
            'success'     => false,
            'http_status' => 403, // 403 Forbidden
            'message'     => 'Akun dinonaktifkan, silakan hubungi admin.',
            'errors'      => [
                'account' => ['Akun Anda telah dinonaktifkan.'],
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Helper — Phone & Email Lookup
    // -----------------------------------------------------------------------

    private function findByEmail(string $email): ?User
    {
        return User::where('email', strtolower(trim($email)))->first();
    }

    private function findByPhone(string $phone): ?User
    {
        $normalized = $this->normalizePhoneNumber($phone);

        return User::where('phone_number', $normalized)
            ->orWhere('phone_number', $this->toLocalFormat($normalized))
            ->first();
    }

    /**
     * Normalisasi nomor HP ke format internasional (+62xxx).
     * '08123...' → '+628123...'
     * '628123...' → '+628123...'
     */
    public function normalizePhoneNumber(string $phone): string
    {
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        return match (true) {
            str_starts_with($cleaned, '+62') => $cleaned,
            str_starts_with($cleaned, '62')  => '+' . $cleaned,
            str_starts_with($cleaned, '0')   => '+62' . substr($cleaned, 1),
            default                           => '+62' . $cleaned,
        };
    }

    private function toLocalFormat(string $internationalPhone): string
    {
        return str_starts_with($internationalPhone, '+62')
            ? '0' . substr($internationalPhone, 3)
            : $internationalPhone;
    }

    // -----------------------------------------------------------------------
    // Helper — Tier & Format
    // -----------------------------------------------------------------------

    /**
     * Temukan threshold tier freeze berikutnya setelah jumlah attempts saat ini.
     * Contoh: attempts=2 → next tier = 3 | attempts=4 → next tier = 6
     */
    private function getNextFreezeTier(int $currentAttempts): ?int
    {
        foreach (array_keys(self::FREEZE_TIERS) as $threshold) {
            if ($currentAttempts < $threshold) {
                return $threshold;
            }
        }

        return null; // Sudah melampaui semua tier
    }

    /**
     * Format user untuk response (field aman saja).
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
