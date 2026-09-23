<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库缓存的驱动表（.env 中 CACHE_STORE=database）。
 *
 * 本文件来自 Laravel 骨架，唯一改动是**为每个字段补上数据库备注**。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary()->comment('缓存键（主键，含前缀）');
            $table->mediumText('value')->comment('序列化后的缓存值');
            $table->bigInteger('expiration')->index()->comment('过期时间（Unix 时间戳）');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary()->comment('锁名（主键）');
            $table->string('owner')->comment('持有者的随机标识；用于判断锁是否已易主');
            $table->bigInteger('expiration')->index()->comment('锁过期时间（Unix 时间戳）；过期即视为可抢占');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
