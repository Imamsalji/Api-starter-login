<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nomor HP unik, nullable
            $table->string('phone_number')->unique()->nullable()->after('email');

            // Status akun: 'active' atau 'inactive'
            $table->enum('status', ['active', 'inactive'])->default('active')->after('phone_number');

            // Jumlah percobaan login gagal berturut-turut
            $table->unsignedTinyInteger('failed_attempts')->default(0)->after('status');

            // Waktu akun di-freeze (null = tidak sedang dibekukan)
            $table->timestamp('frozen_until')->nullable()->after('failed_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
            $table->dropColumn(['phone_number', 'status', 'failed_attempts', 'frozen_until']);
        });
    }
};
