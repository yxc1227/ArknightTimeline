<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 账号与会话的基础表。
 *
 * 本文件来自 Laravel 骨架，唯一改动是**为每个字段补上数据库备注**（保持 schema 无遗漏地自描述）。
 * 业务字段（角色、出处归属）在 2026_09_22_000500 中追加。
 *
 * 约定：列注释写在类型与常用修饰符之后、constrained() 之前 ——
 * constrained() 返回的是外键对象而不是列对象，写在它后面的注释会丢失。
 * timestamps() 无法逐字段加注释，因此展开为两个显式列。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('name')->comment('登录名');
            $table->string('email')->unique()->comment('邮箱，同时作为登录账号');
            $table->timestamp('email_verified_at')->nullable()->comment('邮箱验证时间；为空表示尚未验证');
            $table->string('password')->comment('密码哈希（bcrypt）');
            $table->rememberToken()->comment('「记住我」令牌');
            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary()->comment('邮箱（主键）');
            $table->string('token')->comment('密码重置令牌的哈希');
            $table->timestamp('created_at')->nullable()->comment('签发时间；超时的令牌视为失效');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary()->comment('会话 ID');
            $table->foreignId('user_id')->nullable()->index()->comment('登录用户；为空表示访客会话（浏览时间线无需登录）');
            $table->string('ip_address', 45)->nullable()->comment('客户端 IP（兼容 IPv6 的 45 位长度）');
            $table->text('user_agent')->nullable()->comment('客户端 User-Agent');
            $table->longText('payload')->comment('序列化后的会话数据（base64）');
            $table->integer('last_activity')->index()->comment('最后活动时间（Unix 时间戳）');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
