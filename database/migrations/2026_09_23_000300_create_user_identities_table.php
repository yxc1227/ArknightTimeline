<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 外部身份绑定表。
 *
 * 一个本地账号可以绑定多个外部渠道（鹰角通行证、将来的其他渠道），
 * 而一个外部账号只能对应一个本地账号 —— 这两条各由一条唯一索引保证，
 * 缺一条就会出现「同一份外部身份被两个人认领」或「一个人占两个名额」。
 *
 * 关于**不存令牌**：本表刻意没有 access_token / refresh_token 字段。
 * 外部身份在本系统里只用于回答「你是谁」，不会拿令牌去代用户调用对方的接口。
 * 存下用不到的令牌只会扩大数据泄露的影响面，属于净负债。
 * 若将来真的需要调用对方 API，再加加密列，而不是现在先囤着。
 *
 * 同理没有 raw 原始响应列：`nickname/avatar_url/email` 三个字段已经覆盖全部用途，
 * 「只存会被读到的数据」是这张表唯一需要遵守的隐私纪律。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_identities', function (Blueprint $table) {
            $table->id()->comment('主键');
            $table->foreignId('user_id')
                ->comment('所属本地账号；账号被硬删除时随之清理，软删除期间保留绑定关系以便恢复')
                ->constrained('users')->cascadeOnDelete();

            $table->string('provider', 32)
                ->comment('外部提供方标识，对应 config/identity.php 中 providers 的键');
            $table->string('provider_user_id')
                ->comment('该用户在外部系统中的唯一 ID（OAuth 的 sub，或手工登记的通行证 UID）');

            $table->string('nickname')->nullable()
                ->comment('外部系统返回的昵称，仅作展示参考，不参与本系统的命名与唯一性');
            $table->string('avatar_url', 500)->nullable()
                ->comment('外部系统返回的头像地址；本地未上传头像时作为兜底显示');
            $table->string('email')->nullable()
                ->comment('外部系统返回的邮箱，仅供界面展示；本系统的登录邮箱始终以 users.email 为准');

            $table->string('status', 16)
                ->comment('核验状态：verified 由授权流程或管理员确认，pending 为用户自助声明');
            $table->timestamp('verified_at')->nullable()
                ->comment('转为已核验的时间；pending 时为空');
            $table->foreignId('verified_by')->nullable()
                ->comment('人工核验的操作人；由授权流程自动确认时为空（表示非人工）')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('linked_at')->nullable()
                ->comment('绑定时间；解绑即删除本行，因此该时间等同于「本次绑定自何时开始」');
            $table->timestamp('last_used_at')->nullable()
                ->comment('最后一次用此身份登录的时间；长期不用可用于判断哪些绑定已失效');

            $table->timestamp('created_at')->nullable()->comment('创建时间');
            $table->timestamp('updated_at')->nullable()->comment('最后更新时间');

            // 一个外部账号只能被一个本地账号绑定
            $table->unique(['provider', 'provider_user_id'], 'user_identities_provider_account_unique');
            // 一个本地账号在每个渠道上只能绑一个
            $table->unique(['user_id', 'provider'], 'user_identities_user_provider_unique');

            // 管理员需要按状态筛选待核验的绑定
            $table->index('status', 'user_identities_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_identities');
    }
};
