<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 只改 `characters.kind` 的注释：它此前写的是两档，实际已有三档。
 *
 * 注释是 schema 的文档。两档的注释配上三档的数据，下一个人读到这里就会以为
 * 「npc」是脏数据 —— 而它其实是导入 PRTS 干员名单后必须补上的那一档。
 *
 * 没有新增列、也没有改类型：`kind` 本来就是字符串。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('kind')->default('operator')
                ->comment('人物类型：operator 干员 / historical 历史人物 / npc 剧情人物（现代但非干员）')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->string('kind')->default('operator')
                ->comment('人物类型：operator 干员 / historical 历史人物（君主、贵族、学者等）')
                ->change();
        });
    }
};
