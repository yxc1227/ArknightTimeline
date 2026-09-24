<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人物的头像。
 *
 * 存**相对路径**而不是 URL 或外键：头像文件本体是本地资产（public/assets/avatars/，
 * 与名单快照同一套规矩 —— 版权属站方，不入仓库），数据库里只记「这个人用的是哪一张」。
 *
 * 留空是常态：历史人物没有维基头像，清单或文件缺失时也不影响名单本身的完整性。
 * 页面据此退回「首字方块」，而不是渲染一张碎图。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('wiki_slug')
                ->comment('头像相对路径（public/ 下，如 assets/avatars/terra/W.png）；无图时留空');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
