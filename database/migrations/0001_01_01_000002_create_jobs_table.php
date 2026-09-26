<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 数据库队列的驱动表（.env 中 QUEUE_CONNECTION=database）。
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
        Schema::create('jobs', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('queue')->index()->comment('队列名；worker 按队列消费');
            $table->longText('payload')->comment('序列化后的任务载荷');
            $table->unsignedSmallInteger('attempts')->comment('已尝试执行的次数');
            $table->unsignedInteger('reserved_at')->nullable()->comment('被 worker 占用的时间（Unix 时间戳）；已占用但超时的任务会被重新投递');
            $table->unsignedInteger('available_at')->comment('最早可执行时间（Unix 时间戳），用于延迟投递');
            $table->unsignedInteger('created_at')->comment('入队时间（Unix 时间戳）');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary()->comment('批处理 ID');
            $table->string('name')->comment('批处理名称');
            $table->integer('total_jobs')->comment('任务总数');
            $table->integer('pending_jobs')->comment('尚未完成的任务数');
            $table->integer('failed_jobs')->comment('失败的任务数');
            $table->longText('failed_job_ids')->comment('失败任务的 ID 列表（序列化）');
            $table->mediumText('options')->nullable()->comment('批处理选项（序列化），如完成回调');
            $table->integer('cancelled_at')->nullable()->comment('取消时间（Unix 时间戳）；为空表示未取消');
            $table->integer('created_at')->comment('创建时间（Unix 时间戳）');
            $table->integer('finished_at')->nullable()->comment('完成时间（Unix 时间戳）；为空表示仍在进行中');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->string('uuid')->unique()->comment('失败任务的唯一标识；用于重试或遗忘该任务');
            $table->string('connection')->comment('任务原本所属的连接名');
            $table->string('queue')->comment('任务原本所属的队列名');
            $table->longText('payload')->comment('序列化后的任务载荷');
            $table->longText('exception')->comment('异常堆栈，用于排查失败原因');
            $table->timestamp('failed_at')->useCurrent()->comment('失败时间');

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
