<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds profile-related columns to the users table for Martis profile feature.
 *
 * Install via: php artisan vendor:publish --tag=martis-2fa-migration
 * Or for avatar column: php artisan vendor:publish --tag=martis-avatar-migration
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'profile_picture')) {
                $table->string('profile_picture')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $table->text('two_factor_secret')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'two_factor_recovery_codes')) {
                $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            }
            if (! Schema::hasColumn('users', 'two_factor_last_used_at')) {
                $table->timestamp('two_factor_last_used_at')->nullable()->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $columns = array_filter([
            Schema::hasColumn('users', 'profile_picture') ? 'profile_picture' : null,
            Schema::hasColumn('users', 'two_factor_secret') ? 'two_factor_secret' : null,
            Schema::hasColumn('users', 'two_factor_recovery_codes') ? 'two_factor_recovery_codes' : null,
            Schema::hasColumn('users', 'two_factor_confirmed_at') ? 'two_factor_confirmed_at' : null,
            Schema::hasColumn('users', 'two_factor_last_used_at') ? 'two_factor_last_used_at' : null,
        ]);

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn(array_values($columns));
            });
        }
    }
};
