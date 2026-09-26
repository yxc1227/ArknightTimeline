<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户管理所需的两块数据：账号状态 与 操作日志。
 *
 * 两个刻意的取舍：
 *
 *  1. **users 启用软删除**（与 events 保持一致）。
 *     本项目的核心价值是「谁在什么时候改了什么」，硬删除会把
 *     events.created_by / event_revisions.user_id 等外键置空，
 *     等于抹掉历史归属。软删除让账号「消失」但把归属留在库里。
 *     代价：email 的唯一索引仍会占用该邮箱 —— 由表单校验给出明确提示。
 *
 *  2. **操作日志只增不改**（因此没有 updated_at，与 event_revisions 同构）。
 *     审计表一旦可改就失去意义。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)
                ->comment('账号是否启用；禁用后无法登录，但历史归属与操作日志全部保留')
                ->after('strict_source_scope');
            $table->timestamp('last_login_at')->nullable()
                ->comment('最后登录时间（成功登录才更新）')
                ->after('last_seen_at');
            $table->string('last_login_ip', 45)->nullable()
                ->comment('最后登录来源 IP（兼容 IPv6 的 45 位长度）')
                ->after('last_login_at');
            $table->softDeletes()
                ->comment('软删除时间；非空表示账号已删除，其历史归属与操作日志仍然保留');
        });

        Schema::create('user_activity_logs', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('user_id')->nullable()
                ->comment('被操作用户（日志主体）；账号被硬删除时置空，正常情况下软删除不影响本字段')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()
                ->comment('操作人；为空表示系统自动动作（如首次登录回填）')
                ->constrained('users')->nullOnDelete();
            $table->string('action')
                ->comment('动作类型（UserAction 枚举）：created / updated / deleted / restored / password_reset / activated / deactivated / logged_in');
            $table->string('description')
                ->comment('人类可读的动作说明，含具体字段变化，用于详情页直接展示');
            /*
             * 列名刻意叫 field_changes 而不是 changes。
             *
             * Eloquent 的 HasAttributes 特性自带一个 protected $changes（模型属性脏值缓存）。
             * 列名若取 changes，就会出现「类外 $log->changes 走 __get 拿到数据库列、
             * 类内 $this->changes 直接命中那个内部数组」的分裂 ——
             * 而后者恒为初始空数组，于是模型内部方法读到的永远是空值，且不报任何错。
             */
            $table->json('field_changes')->nullable()
                ->comment('字段级变化（JSON）：{字段: {label, from, to}}，用于精确回溯改了什么');
            $table->string('ip_address', 45)->nullable()
                ->comment('操作来源 IP，审计用');
            $table->timestamp('created_at')->nullable()
                ->comment('发生时间；日志只增不改，因此没有 updated_at');

            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_activity_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['is_active', 'last_login_at', 'last_login_ip']);
        });
    }
};
