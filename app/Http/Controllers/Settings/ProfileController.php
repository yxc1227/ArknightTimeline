<?php

namespace App\Http\Controllers\Settings;

use App\Enums\IdentityProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Http\Requests\UploadAvatarRequest;
use App\Services\AvatarService;
use App\Services\UserManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * 个人账号设置（本人操作自己）。
 *
 * 这是账号体系里唯一不需要管理员的地方，也是「头像上传」「昵称修改」
 * 「绑定 / 解绑外部身份」三个需求的落点 —— 这些操作如果只能由管理员代做，
 * 功能等于没做。
 *
 * 三条边界写在这里，且都不靠界面约束，而由服务层与校验规则保证：
 *   · 只能改自己的昵称（登录名与邮箱走管理员通道）；
 *   · 头像一律重新编码后再落盘；
 *   · 解绑最后一个登录方式会被拒绝（见 IdentityManager::unlink）。
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly UserManager $users,
        private readonly AvatarService $avatars,
    ) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('settings.profile', [
            'user' => $user,
            'identities' => $user->identities()->get(),
            'providers' => IdentityProvider::all(),
            // 只取最近几条：这里是「我最近动过什么」的自我核对，
            // 完整的审计轨迹在管理员侧的账号详情页
            'recentLogs' => $user->activityLogs()->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function updateNickname(UpdateProfileRequest $request): RedirectResponse
    {
        $this->users->updateOwnProfile($request->user(), (string) $request->validated('nickname'));

        return back()->with('status', '昵称已更新。');
    }

    public function updateAvatar(UploadAvatarRequest $request): RedirectResponse
    {
        try {
            $this->avatars->store($request->user(), $request->file('avatar'));
        } catch (RuntimeException $e) {
            // 图片损坏、GD 编码失败等属于可预期的用户输入问题，
            // 不该以 500 呈现 —— 让人换个文件再试即可
            return back()->withErrors(['avatar' => $e->getMessage()]);
        }

        return back()->with('status', '头像已更新。');
    }

    public function destroyAvatar(Request $request): RedirectResponse
    {
        $this->avatars->remove($request->user());

        return back()->with('status', '头像已移除，界面将显示首字方块。');
    }

    public function updatePassword(ChangePasswordRequest $request): RedirectResponse
    {
        $this->users->changeOwnPassword($request->user(), (string) $request->validated('password'));

        return back()->with('status', '密码已更新。此后可用邮箱或登录名加密码登录。');
    }
}
