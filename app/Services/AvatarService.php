<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * 头像文件的存取。
 *
 * 三条设计决定，都是为了「上传的图片不会被当成攻击载荷」：
 *
 *  1. **一律重新编码**，而不是原样保存。上传的文件可以同时是合法 JPEG 和
 *     合法 ZIP（多态文件），原样落盘再交给浏览器就多了一条攻击路径；
 *     经过 GD 解码再编码，只保留像素，EXIF（含 GPS 坐标）与任何附加数据都会被丢掉。
 *  2. **不接受 SVG**。SVG 可以内嵌 <script>，被浏览器当作图片加载时仍会执行，
 *     是最典型的存储型 XSS 载体。白名单里只有 jpg / png。
 *  3. **不写进 web 根目录**，也不依赖 `storage:link` 软链，而是由 AvatarController
 *     受控输出：这样 Content-Type 与 nosniff 由我们自己决定，
 *     即使有人绕过校验塞进了 HTML，也不会被当作页面渲染。
 *
 * 目录里刻意只放重新编码后的 JPEG，文件名随机 —— 原始文件名不落盘，
 * 避免「上传文件名带路径穿越 / 带脚本后缀」这一类历史问题。
 */
class AvatarService
{
    public function __construct(
        private readonly UserManager $manager,
    ) {}

    /**
     * 保存新头像并清掉旧文件，返回相对路径。
     */
    public function store(User $user, UploadedFile $file): string
    {
        $disk = $this->disk();
        $directory = $this->directory().'/'.$user->getKey();

        $path = $directory.'/'.Str::random(32).'.jpg';
        $binary = $this->normalize($file);

        if (! Storage::disk($disk)->put($path, $binary)) {
            throw new RuntimeException('头像写入失败，请稍后重试。');
        }

        $previous = $user->avatar_path;

        $this->manager->updateAvatar($user, $path);

        $this->forget($previous);

        return $path;
    }

    /** 移除头像文件，并让账号回落到首字方块（或外部头像）。 */
    public function remove(User $user): void
    {
        $previous = $user->avatar_path;

        if ($previous === null) {
            return;
        }

        $this->manager->updateAvatar($user, null);

        $this->forget($previous);
    }

    /** 删除磁盘上的文件。失败不影响主流程 —— 数据一致性以数据库列为准。 */
    public function forget(?string $path): void
    {
        if (! filled($path)) {
            return;
        }

        try {
            Storage::disk($this->disk())->delete($path);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** 该文件是否属于头像目录（防止通过传入任意路径删除无关文件）。 */
    public function owns(?string $path): bool
    {
        return filled($path) && str_starts_with($path, $this->directory().'/');
    }

    /**
     * 解码 + 居中裁剪成正方形 + 缩放 + 重新编码为 JPEG。
     *
     * 输出统一为 JPEG：容器里 GD 没有编译 WebP（gd_info() 里 WebP Support 为空），
     * 而 PNG 存照片体积大得多。PNG 的透明区域会先被压平到站点面板色，
     * 否则透明部分在 JPEG 里会变成纯黑，在深色界面之外看起来像图片坏了。
     */
    private function normalize(UploadedFile $file): string
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException('无法解析该图片，请换一张再试。');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $size = (int) config('identity.avatar.size', 256);

        // 居中裁剪出最大的正方形，再缩放 —— 直接拉伸会把人物压扁
        $edge = min($width, $height);
        $srcX = (int) (($width - $edge) / 2);
        $srcY = (int) (($height - $edge) / 2);

        $canvas = imagecreatetruecolor($size, $size);

        // 先铺底色，让 PNG 的透明区域压平到站点面板色
        [$r, $g, $b] = (array) config('identity.avatar.flatten_color', [0x1B, 0x1B, 0x1B]);
        imagefilledrectangle($canvas, 0, 0, $size, $size, imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b));

        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $size, $size, $edge, $edge);

        ob_start();
        imagejpeg($canvas, null, (int) config('identity.avatar.quality', 88));
        $binary = (string) ob_get_clean();

        imagedestroy($canvas);
        imagedestroy($source);

        if ($binary === '') {
            throw new RuntimeException('头像编码失败，请换一张再试。');
        }

        return $binary;
    }

    public function disk(): string
    {
        return (string) config('identity.avatar.disk', 'public');
    }

    public function directory(): string
    {
        return trim((string) config('identity.avatar.directory', 'avatars'), '/');
    }
}
