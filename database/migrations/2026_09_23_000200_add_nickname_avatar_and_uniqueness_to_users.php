<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 昵称与登录名分离，并为两者加上全服唯一约束；同时加入头像字段。
 *
 * 三件事放在一个迁移里，是因为它们互相依赖、必须**一步到位**：
 * 昵称的唯一索引只有在回填完成之后才建得起来，而回填的来源正是被重命名的 display_name。
 *
 * 曾经的 `display_name`（显示名）直接改名为 `nickname`（昵称），而不是新增一列：
 * 两者在本系统里是同一个概念（对外的展示名），并存只会让人不知道该读哪一个。
 *
 * ⚠️ 回填过程对重名做了**去重改写**（追加 -2 / -3）。这一步不可逆 ——
 * down() 只能把列名改回去，无法还原被改写的昵称。
 * 这是刻意的取舍：与其让迁移在遇到重名时直接失败、把升级卡死，
 * 不如让它完成升级并把冲突结果明确记录在下方日志里。
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- 1. 改名 -------------------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('display_name', 'nickname');
        });

        // ---- 2. 回填并去重 -------------------------------------------------
        $this->dedupe('name');
        $this->backfillNicknames();

        // ---- 3. 昵称改为非空 -----------------------------------------------
        // 允许为空，等于允许「一批人都没有昵称」——那唯一索引就形同虚设
        // （MySQL 与 SQLite 的唯一索引都允许多个 NULL）。
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname')->nullable(false)
                ->comment('昵称：对外展示名，与登录名分离，全服唯一')
                ->change();
        });

        // ---- 4. 唯一索引与头像 ---------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            // 唯一索引不排除软删除行：被删除的账号仍占用它的登录名与昵称。
            // 这是有意的 —— 恢复账号时不必重新协调命名，且能防止
            // 「删掉某人再用同名注册」这种身份冒用。校验层必须与之保持一致
            // （Rule::unique 默认就包含软删除行，见 StoreUserRequest 的注释）。
            $table->unique('name', 'users_name_unique');
            $table->unique('nickname', 'users_nickname_unique');

            $table->string('avatar_path')->nullable()
                ->comment('本地头像文件的相对路径（public 磁盘）；为空表示未上传，界面回落到首字方块')
                ->after('nickname');

            /*
             * 「密码是否为本人可知」的标记。
             *
             * users.password 是非空的，但**非空不等于本人知道**：外部渠道注册的账号
             * 会写入一个随机占位哈希，本人永远无从得知。若只看 password 列，
             * 系统会误判「他有密码，可以解绑最后一个身份」，结果把人永久锁在门外。
             * 因此单独立一个时间戳，只回答「密码有没有被设置为本人可知的值」。
             */
            $table->timestamp('password_set_at')->nullable()
                ->comment('密码被设置为「本人可知」的时间；为空表示现有密码是随机占位值，此时禁止解绑最后一个登录身份')
                ->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_name_unique');
            $table->dropUnique('users_nickname_unique');
            $table->dropColumn(['avatar_path', 'password_set_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // 注释要连同列一起还原：本项目的迁移注释守卫会比对
            // 「列定义数」与「comment 数」，少写一个就会失败 —— 这是好事，
            // 它逼着 down() 也回到与原始 schema 完全一致的状态
            $table->string('nickname')->nullable()
                ->comment('界面显示名；留空时回落到 name')
                ->change();
            $table->renameColumn('nickname', 'display_name');
        });
    }

    /**
     * 把某个列里的重复值改写为唯一值（保留最早出现的那条不变）。
     *
     * 比较用 mb_strtolower：MySQL 的 utf8mb4_unicode_ci 与 SQLite 的唯一索引
     * 对大小写的处理不同（前者不区分、后者区分），而两种环境下都要能建起索引，
     * 因此按「更严格」的一侧去重 —— 在 SQLite 上多改写几个不会出问题。
     */
    private function dedupe(string $column): void
    {
        $used = [];
        $rewritten = 0;

        foreach (DB::table('users')->orderBy('id')->get(['id', $column]) as $row) {
            $value = trim((string) $row->{$column});

            if ($value === '') {
                $value = 'user-'.$row->id;
            }

            $unique = $this->uniqueValue($value, $used);
            $used[mb_strtolower($unique)] = true;

            if ($unique !== $row->{$column}) {
                DB::table('users')->where('id', $row->id)->update([$column => $unique]);
                $rewritten++;
            }
        }

        if ($rewritten > 0) {
            // 迁移不返回值，日志是唯一能让运维知道「数据被动过」的地方
            logger()->warning("users.{$column} 存在重复值，已在迁移中改写为唯一值", [
                'rewritten' => $rewritten,
            ]);
        }
    }

    /** 昵称为空的历史行回填为登录名，然后整体去重。 */
    private function backfillNicknames(): void
    {
        $used = [];
        $rewritten = 0;

        foreach (DB::table('users')->orderBy('id')->get(['id', 'name', 'nickname']) as $row) {
            $existing = trim((string) $row->nickname);
            $candidate = $existing !== '' ? $existing : trim((string) $row->name);

            if ($candidate === '') {
                $candidate = 'user-'.$row->id;
            }

            $unique = $this->uniqueValue($candidate, $used);
            $used[mb_strtolower($unique)] = true;

            if ($unique !== $row->nickname) {
                DB::table('users')->where('id', $row->id)->update(['nickname' => $unique]);
                $rewritten++;
            }
        }

        if ($rewritten > 0) {
            logger()->warning('users.nickname 已回填并去重', ['rewritten' => $rewritten]);
        }
    }

    /**
     * @param  array<string, true>  $used
     */
    private function uniqueValue(string $value, array $used): string
    {
        if (! isset($used[mb_strtolower($value)])) {
            return $value;
        }

        $suffix = 2;

        do {
            $candidate = $value.'-'.$suffix++;
        } while (isset($used[mb_strtolower($candidate)]));

        return $candidate;
    }
};
