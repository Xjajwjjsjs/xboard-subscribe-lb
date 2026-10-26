<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 负载均衡组：一个组在订阅里只表现为一个节点（名称 = name）
        if (!Schema::hasTable('sub_lb_groups')) {
            Schema::create('sub_lb_groups', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name', 64)->unique();
                $table->tinyInteger('enabled')->default(1);
                $table->double('sort')->default(0);
                $table->integer('interval_seconds')->default(3600);   // 重新分配间隔（只限制重新随机的频率）
                $table->string('strategy', 32)->default('random');
                $table->text('strategy_options')->nullable();
                $table->string('health_mode', 32)->default('heartbeat'); // heartbeat | probe | heartbeat+probe | none
                $table->string('remark', 255)->nullable();
                $table->string('state', 32)->nullable();                 // 巡检结论 ok|degraded|draining_only|failopen|empty
                $table->text('state_detail')->nullable();
                $table->integer('state_changed_at')->nullable();
                $table->integer('checked_at')->nullable();
                $table->integer('resolve_server_id')->nullable();        // 域名入口解析来源节点（v2_server.id）
                $table->integer('resolve_interval')->default(1800);
                $table->integer('resolved_at')->nullable();
                $table->text('resolve_detail')->nullable();
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // 组成员：一台实际节点（v2_server）只能属于一个组
        if (!Schema::hasTable('sub_lb_members')) {
            Schema::create('sub_lb_members', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('group_id')->index();
                $table->integer('server_id')->unique();
                $table->string('status', 16)->default('active');         // active | draining | disabled
                $table->tinyInteger('standalone')->default(0);           // 1=同时按自身名称独立下发
                $table->string('health_override', 8)->nullable();        // NULL=自动 | down | up
                $table->text('health_note')->nullable();
                $table->integer('health_override_at')->nullable();
                $table->string('health_override_by', 191)->nullable();
                $table->string('probe_state', 8)->nullable();
                $table->integer('probe_ok_streak')->default(0);
                $table->integer('probe_fail_streak')->default(0);
                $table->integer('probe_checked_at')->nullable();
                $table->integer('probe_changed_at')->nullable();
                $table->text('probe_detail')->nullable();
                $table->string('hb_state', 8)->nullable();
                $table->string('managed', 16)->nullable();               // dns=解析器管理的成员 | dns-source=解析来源节点
                $table->integer('created_at');
                $table->integer('updated_at');
            });
        }

        // 分配状态：按「用户 + 组」一行；version 做乐观锁（CAS）
        if (!Schema::hasTable('sub_lb_assignments')) {
            Schema::create('sub_lb_assignments', function (Blueprint $table) {
                $table->integer('user_id');
                $table->integer('group_id');
                $table->integer('server_id');
                $table->integer('assigned_at');
                $table->integer('version')->default(1);
                $table->string('reason', 64)->nullable();
                $table->integer('previous_server_id')->nullable();
                $table->primary(['user_id', 'group_id']);
                $table->index(['group_id', 'server_id']);
            });
        }

        // 运行事件（保留 14 天）
        if (!Schema::hasTable('sub_lb_events')) {
            Schema::create('sub_lb_events', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('group_id')->nullable();
                $table->integer('server_id')->nullable();
                $table->string('type', 64);
                $table->string('level', 16)->default('info');
                $table->text('detail')->nullable();
                $table->integer('created_at')->index();
                $table->index(['group_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_lb_events');
        Schema::dropIfExists('sub_lb_assignments');
        Schema::dropIfExists('sub_lb_members');
        Schema::dropIfExists('sub_lb_groups');
    }
};
