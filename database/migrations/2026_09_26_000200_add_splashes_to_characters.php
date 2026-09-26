<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 干员的立绘。
 *
 * 与头像（`characters.avatar`）、徽记（`places.logo`）同一套规矩：存**相对路径**，
 * 不存外键也不存站外 URL —— 图是本地资产（`public/assets/splashes/terra/`），
 * 库里只记「这个人用的是哪几张」。
 *
 * 与头像的唯一差别是**一个人可以有多张**：同一名干员有精英一与精英二两套立绘
 * （同一个人的不同状态，不是不同的人），因此这一列是 JSON 而不是字符串：
 *
 *     {"1": "assets/splashes/terra/克洛丝_1.avif",
 *      "2": "assets/splashes/terra/克洛丝_2.avif"}
 *
 * 键是**变体号**（1 = 精英一，2 = 精英二），展示名由
 * `App\Models\Character::SPLASH_VARIANTS` 给出 —— 存键而不是存「精英二」这三个字，
 * 是为了让这一列的语义与来源（PRTS 的 `立绘_<干员>_2.png`）一一对应。
 *
 * 留空是常态，且**分两种情况**：塔卫二的人员在来源侧根本没有立绘（fz.wiki 的
 * 干员条目只有文字与图标），历史人物则不在干员名单里。两者都留空，
 * 页面据此整块不渲染 —— 而不是渲染一张碎图。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->json('splashes')->nullable()->after('avatar')
                ->comment('立绘相对路径表（变体号 => public/ 下路径）；无图时留空');
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('splashes');
        });
    }
};
