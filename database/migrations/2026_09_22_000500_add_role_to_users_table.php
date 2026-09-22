<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('viewer')->after('email');   // UserRole
            $table->string('display_name')->nullable()->after('name');
            $table->boolean('strict_source_scope')->default(true)->after('role');
            $table->timestamp('last_seen_at')->nullable();
        });

        // 出处归属：限制编辑者只能改动自己负责的出处相关条目（可被 users.strict_source_scope 关闭）
        Schema::create('source_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

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
