<?php
// app/Http/Controllers/Api/LoginController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            identity: $request->validated('identity'),
            password: $request->validated('password'),
        );

        // http_status ditentukan oleh AuthService sesuai kondisi:
        // 200 → sukses
        // 401 → invalid credentials
        // 423 → akun frozen (Locked)
        // 403 → akun inactive (Forbidden)
        $status = $result['http_status'];
        unset($result['http_status']); // Bersihkan sebelum dikirim ke client

        return response()->json($result, $status);
    }
}
