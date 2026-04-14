<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (! Schema::hasColumn("users", "profile_picture")) {
                $table->string("profile_picture", 255)->nullable()->after("email");
            }
        });
    }

    public function down(): void
    {
        Schema::table("users", function (Blueprint $table) {
            if (Schema::hasColumn("users", "profile_picture")) {
                $table->dropColumn("profile_picture");
            }
        });
    }
};
