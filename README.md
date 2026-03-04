# 🚀 Secure Dual-Auth API (Laravel Sanctum)

Dokumentasi API untuk sistem autentikasi tingkat lanjut dengan fitur deteksi identitas ganda (Email/No. HP) dan sistem keamanan **Tiered Account Freeze**.

## 📌 Fitur Utama

- **Dual-Identity Login**: Login menggunakan Email atau Nomor HP dalam satu field.
- **Phone Normalization**: Mengubah format nomor HP (08xx/62xx) menjadi standar internasional (+62).
- **Tiered Security Lockout**:
    - 3x Gagal: Akun dibekukan 3 menit.
    - 6x Gagal: Akun dibekukan 10 menit.
    - 9x Gagal: Akun dinonaktifkan (Inactive) & butuh bantuan Admin.
- **Bearer Token Authentication**: Menggunakan Laravel Sanctum.

---

## 🛠️ Persyaratan Sistem

- Laravel 11.x
- PHP 8.2+
- MySQL / PostgreSQL
- Laravel Sanctum

---

## 💾 Perubahan Database (`users` table)

| Column            | Type        | Description                                              |
| :---------------- | :---------- | :------------------------------------------------------- |
| `phone_number`    | `string`    | Unik, menyimpan nomor HP format internasional.           |
| `failed_attempts` | `integer`   | Menghitung jumlah kegagalan login berturut-turut.        |
| `status`          | `enum`      | Status akun: `active`, `frozen`, `inactive`.             |
| `last_failed_at`  | `timestamp` | Waktu kegagalan terakhir untuk menghitung durasi freeze. |

---

## 🛰️ Dokumentasi Endpoint

### 1. Login API

Mendapatkan token akses berdasarkan kredensial user.

- **URL:** `/api/v1/login`
- **Method:** `POST`
- **Headers:** `Accept: application/json`

**Request Body:**

```json
{
    "identity": "08123456789", // Bisa berupa email atau nomor HP
    "password": "PasswordAman123!"
}
```
