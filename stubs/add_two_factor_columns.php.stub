<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
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
                // Replay protection — TwoFactorService::verifyAndTrack() stores
                // the timestamp of the last successfully used TOTP step so codes
                // from the same or earlier step are rejected on subsequent
                // verifications. Without this column the service falls back to
                // plain TOTP verification (no replay guard).
                $table->timestamp('two_factor_last_used_at')->nullable()->after('two_factor_confirmed_at');
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter([
            Schema::hasColumn('users', 'two_factor_secret') ? 'two_factor_secret' : null,
            Schema::hasColumn('users', 'two_factor_recovery_codes') ? 'two_factor_recovery_codes' : null,
            Schema::hasColumn('users', 'two_factor_confirmed_at') ? 'two_factor_confirmed_at' : null,
            Schema::hasColumn('users', 'two_factor_last_used_at') ? 'two_factor_last_used_at' : null,
        ]));

        if ($columns === []) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
