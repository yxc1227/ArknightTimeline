<?php

namespace Tests\Feature;

use App\Models\AiProposal;
use App\Models\Annotation;
use App\Models\Character;
use App\Models\Era;
use App\Models\Event;
use App\Models\EventLock;
use App\Models\EventRevision;
use App\Models\Faction;
use App\Models\Source;
use App\Models\Tag;
use App\Models\TimelineAnomaly;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\UserIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * 列名不得与 Eloquent 的内部属性重名。
 *
 * 这是一个**会静默出错**的陷阱，因此值得单开一条守卫：
 * Eloquent 的 HasAttributes 特性自带 `protected $changes`（属性脏值缓存）。
 * 如果某张表也有一个叫 changes 的列，就会出现分裂行为 ——
 *
 *   · 类外 `$log->changes`  → 走 __get，拿到数据库列，一切正常；
 *   · 类内 `$this->changes` → 直接命中那个 protected 内部数组，恒为初始空数组。
 *
 * 于是模型自己的方法读到的永远是空值，而且不报错、不告警，
 * 只在界面上表现为「明明有数据却什么都不显示」。
 * 本项目正是踩过这一条（审计日志的字段级变化整列为空）才补上这个测试。
 */
class ModelAttributeCollisionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<class-string<Model>> */
    private const MODELS = [
        User::class,
        UserActivityLog::class,
        UserIdentity::class,
        Event::class,
        EventRevision::class,
        EventLock::class,
        Annotation::class,
        Era::class,
        Faction::class,
        Character::class,
        Source::class,
        Tag::class,
        AiProposal::class,
        TimelineAnomaly::class,
    ];

    public function test_no_column_shadows_an_eloquent_internal_property(): void
    {
        $internals = $this->eloquentPropertyNames();
        $violations = [];

        foreach (self::MODELS as $class) {
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (Schema::getColumnListing($table) as $column) {
                if (in_array($column, $internals, true)) {
                    $violations[] = sprintf('%s 表的 %s 列 ← %s', $table, $column, $class);
                }
            }
        }

        $this->assertSame([], $violations, "以下列名与 Eloquent 内部属性重名，类内访问会读到错误的值：\n"
            .implode("\n", $violations));
    }

    public function test_the_guard_itself_detects_a_known_collision(): void
    {
        // 反向验证守卫有效：changes 确实是 Eloquent 的内部属性。
        // 若哪天框架移除了它，这条会失败，提醒我们更新上面的说明而不是让守卫悄悄失效。
        $this->assertContains('changes', $this->eloquentPropertyNames());
        $this->assertContains('attributes', $this->eloquentPropertyNames());
    }

    /**
     * Model 自身、其全部父类与 trait 的声明属性名。
     *
     * 用反射而不是硬编码清单：框架新增内部属性时会自动被纳入，
     * 否则这份守卫会随着升级慢慢失效。
     *
     * @return list<string>
     */
    private function eloquentPropertyNames(): array
    {
        $classes = [Model::class];

        for ($parent = get_parent_class(Model::class); $parent !== false; $parent = get_parent_class($parent)) {
            $classes[] = $parent;
        }

        foreach (class_uses_recursive(Model::class) as $trait) {
            $classes[] = $trait;
            $classes = array_merge($classes, array_keys(trait_uses_recursive($trait)));
        }

        $names = [];

        foreach (array_unique($classes) as $class) {
            if (! class_exists($class) && ! trait_exists($class)) {
                continue;
            }

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                $names[] = $property->getName();
            }
        }

        return array_values(array_unique($names));
    }
}
