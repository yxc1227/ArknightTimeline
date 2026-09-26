<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * 头像文件的受控输出。
 *
 * 为什么不让 web 服务器直接托管这些文件：
 *
 *  1. 不必依赖 `storage:link` 软链。少一个「部署时忘了执行就整站头像 404」
 *     的隐性步骤，在共享主机或 Windows 上也不会有软链权限问题。
 *  2. Content-Type 与 nosniff 由我们自己写死。即使有一天上传校验被绕过、
 *     目录里出现了一个 HTML 文件，它也只会以 image/jpeg 返回，
 *     绝不会被浏览器当成页面渲染。
 *
 * 头像是**公开可读**的：它会出现在时间线的标注、版本记录旁边，
 * 与「用户名可见」是同一层信息。URL 里的 v 段只是缓存指纹而不是权限凭据，
 * 因此不校验它 —— 校验了反而会让「换了头像后旧链接立刻 404」，
 * 而那正是浏览器缓存里正在用的链接。
 */
class AvatarController extends Controller
{
    public function show(int $user, ?string $v = null): Response
    {
        // withTrashed：被删除账号的历史贡献旁边仍然显示它的头像，
        // 突然变成灰块会让整个时间线看起来像坏了
        $model = User::withTrashed()->find($user);
        $path = $model?->avatar_path;

        if (blank($path)) {
            abort(404);
        }

        $disk = Storage::disk((string) config('identity.avatar.disk', 'public'));

        if (! $disk->exists($path)) {
            // 数据库有记录但文件不在（手工清理过磁盘等）：当作没有头像，
            // 前端会回落到首字方块，而不是显示一个碎图
            abort(404);
        }

        return response($disk->get($path), 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) $disk->size($path),
            'Content-Disposition' => 'inline; filename="avatar.jpg"',
            'X-Content-Type-Options' => 'nosniff',
            // 文件名带随机串、URL 带路径哈希，内容一旦变化 URL 必然变化，
            // 因此可以放心地让浏览器长期缓存
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
