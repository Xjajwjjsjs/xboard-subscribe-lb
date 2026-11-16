<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('sub_lb_members', 'weight')) {
            Schema::table('sub_lb_members', function (Blueprint $table) {
                $table->unsignedInteger('weight')->default(1);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sub_lb_members', 'weight')) {
            Schema::table('sub_lb_members', function (Blueprint $table) {
                $table->dropColumn('weight');
            });
        }
    }
};
