<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 地名的**别名**。
 *
 * 书的年表里同一个地方往往有几个写法，而 `events.location` 是照原文抄的：
 * 「乌萨斯」与「乌萨斯帝国」是同一个国家，「炎国 · 尚蜀」一句话里同时出现两级地名。
 * 只按 `places.name` 做包含匹配，这两类条目就永远挂不上结构化链接 —— 而这不是「留空」，
 * 是**静默缺失**：读者看到地点一栏写着「乌萨斯」，却发现没有可点的链接，
 * 只会以为这个地名本仓库没收录。
 *
 * 因此别名是**数据**而不是匹配规则：把「书里确实这么叫过」的写法记进字典，
 * 匹配时一并参与最长匹配。宁可少记一个别名，也不要用模糊规则去猜 ——
 * 猜错会挂到另一个地名上，而错的链接比没有链接更难发现。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->json('aliases')->nullable()
                ->comment('书里用过的其他写法，参与 location 匹配（如「乌萨斯帝国」的「乌萨斯」）')
                ->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
