<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户角色与出处归属。
 *
 * 这一迁移把 Laravel 骨架里的通用 users 表改造成「协作账号」：
 * 角色决定能否编辑 / 审核 AI 提案 / 处置一致性异常，出处归属决定编辑范围。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')
                ->comment('角色（UserRole 枚举）：viewer 访客 / editor 编辑者 / reviewer 审核员 / admin 管理员')
                ->after('email');
            $table->string('display_name')->nullable()
                ->comment('界面显示名；留空时回落到 name')
                ->after('name');
            $table->boolean('strict_source_scope')->default(true)
                ->comment('是否把编辑范围限制在自己负责的出处内；默认开启——权限缺省必须更严而不是更松')
                ->after('role');
            $table->timestamp('last_seen_at')->nullable()->comment('最后活跃时间');
        });

        // 出处归属：限制编辑者只能改动自己负责的出处相关条目（可被 users.strict_source_scope 关闭）
        Schema::create('source_user', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('source_id')->comment('出处')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->comment('负责该出处的编辑者')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            $table->unique(['source_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'display_name', 'strict_source_scope', 'last_seen_at']);
        });
    }
};
