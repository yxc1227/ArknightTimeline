<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 地名树（/places）。
 *
 * 折叠、定位、选中都在前端（见 app.js 的 Places 模块），这里守的是**服务端必须给出的契约**：
 * 行序深度优先、每行带 depth / parent / slug，有可见下辖的行才给折叠钮，
 * 筛选后「命中」与「仅为提供上下文的祖先」带不同标记，命中片段包成高亮。
 * 这些属性是前端逻辑的全部输入 —— 少了任何一个，树就只是看起来像棵树。
 */
class PlaceTreeTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $name, string $kind = 'settlement', ?Place $parent = null): Place
    {
        return Place::create([
            'name' => $name,
            'slug' => 'place-'.$name,
            'kind' => $kind,
            'parent_id' => $parent?->id,
            'world' => 'terra',
        ]);
    }

    public function test_rows_carry_the_metadata_the_tree_needs(): void
    {
        $nation = $this->place('测试国', 'nation');
        $this->place('测试城', 'city', $nation);

        $html = $this->get(route('places.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-place-tree', $html);

        // 行的三个 datum：slug 供深链与选中、depth 供折叠结算、parent 供展开祖先
        $this->assertStringContainsString('data-slug="'.$nation->slug.'"', $html);
        $this->assertStringContainsString('data-depth="1"', $html);
        $this->assertStringContainsString('data-parent="'.$nation->id.'"', $html);

        // 有下辖的行才给折叠钮；叶子留同宽占位（不带 data-place-toggle）
        $this->assertSame(1, substr_count($html, 'data-place-toggle'));
    }

    public function test_search_highlights_hits_and_marks_context_rows(): void
    {
        $nation = $this->place('测试国', 'nation');
        $this->place('测试城', 'city', $nation);
        $this->place('无关镇', 'settlement', $nation);

        $html = $this->get(route('places.index', ['q' => '测试城']))->assertOk()->getContent();

        // 命中片段包成既有的高亮笔法 <mark class="hit">
        $this->assertStringContainsString('<mark class="hit">测试城</mark>', $html);

        // 祖先只是上下文（data-hit=0），命中行才是结果（data-hit=1）
        $this->assertMatchesRegularExpression(
            '/data-slug="'.$nation->slug.'"[^>]*data-hit="0"/s',
            $html,
            '被保留的祖先行没有标成「仅为上下文」',
        );

        $this->assertMatchesRegularExpression(
            '/data-hit="1"/',
            $html,
            '命中行没有标成命中',
        );
    }

    public function test_a_row_without_visible_children_gets_no_toggle(): void
    {
        $nation = $this->place('测试国', 'nation');
        $this->place('测试城', 'city', $nation);

        // 按类型筛掉子级：父级仍在（祖先必须保留），但它已没有可见下辖 ——
        // 折叠钮不该出现，否则点了没有任何反应
        $html = $this->get(route('places.index', ['kind' => 'nation']))->assertOk()->getContent();

        $this->assertStringContainsString('data-slug="'.$nation->slug.'"', $html);
        $this->assertSame(0, substr_count($html, 'data-place-toggle'));
    }

    public function test_the_kind_marker_renders_with_its_kind(): void
    {
        $this->place('测试城', 'city');

        $this->get(route('places.index'))
            ->assertOk()
            ->assertSee('data-kind="city"', false);
    }

    /**
     * 叶子行的占位是一个**独立的布局盒子**（`.place-gutter`），不复用按钮的类名 ——
     * 共用类名会让按钮的每一条样式（边框、`+ / −` 字形、悬停高亮、手型光标）渗到占位上，
     * 无下辖的地名一度在鼠标移上去时亮起一个空方框（已实际报过一次）。
     *
     * 这里只能校验标记与 CSS（没有浏览器）：占位类必须独立，悬停高亮必须限定在真按钮上。
     */
    /**
     * 地名树也有一条与时间线同款的快速导航：粘在顶栏之下的顶层节点目录。
     *
     * 容器由服务端渲染（因此这里能断言），条目由 JS 按**当前 DOM 里的行**生成 ——
     * 筛选是服务端做的，行集一变目录跟着变，不会留下跳不到的入口；
     * 顶层节点不足三条时整条收起来。
     */
    public function test_the_place_tree_exposes_a_quick_nav_of_root_nodes(): void
    {
        $nation = $this->place('测试国', 'nation');
        $this->place('测试城', 'city', $nation);

        $html = $this->get(route('places.index'))->assertOk()->getContent();

        $this->assertStringContainsString('id="place-nav"', $html);
        $this->assertStringContainsString('class="quick-nav"', $html);

        // 组件与跳转挂点只活在 JS / CSS 里（条目是前端生成的），因此静态校验
        $js = (string) file_get_contents(public_path('assets/app.js'));

        $this->assertStringContainsString('data-place-jump', $js, '地名目录条没有跳转挂点');
        $this->assertStringContainsString('renderNav', $js, '地名目录条没有生成逻辑');

        $css = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents(public_path('assets/app.css')),
        );

        $this->assertMatchesRegularExpression(
            '/\.quick-nav\s*\{[^}]*position:\s*sticky/s',
            $css,
            '快速导航条不再粘在顶栏之下',
        );
    }

    public function test_the_leaf_placeholder_is_not_a_button(): void
    {
        // 注释里会讨论这条选择器本身（那正是它被记下来的理由），先剥掉注释再匹配规则
        $css = (string) preg_replace(
            '#/\*.*?\*/#s',
            '',
            (string) file_get_contents(public_path('assets/app.css')),
        );

        // 裸的 .place-toggle:hover 会按类名命中，谁共用这个类谁就被点亮
        $this->assertDoesNotMatchRegularExpression(
            '/\.place-toggle:hover/',
            $css,
            '存在未限定的 .place-toggle:hover —— 共用该类名的元素也会跟着亮',
        );

        $this->assertMatchesRegularExpression(
            '/\.place-gutter\s*\{[^}]*\}/s',
            $css,
            '叶子行的占位没有自己的样式（.place-gutter）',
        );

        $nation = $this->place('测试国', 'nation');
        $this->place('测试城', 'city', $nation);

        $html = $this->get(route('places.index'))->assertOk()->getContent();

        // 标记侧：叶子行渲染独立占位，而不是共用按钮类名的元素
        $this->assertStringContainsString('class="place-gutter"', $html);
        $this->assertStringNotContainsString('place-toggle--leaf', $html);
    }
}
