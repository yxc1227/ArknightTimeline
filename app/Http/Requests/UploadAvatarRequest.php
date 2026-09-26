<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 上传头像。
 *
 * 规则里 `image` 与 `mimes` 必须**同时**存在，这不是冗余：
 *
 *  · `image` 只校验「它是一张能被 getimagesize() 读出的图片」，
 *    而它的允许清单里**包含 svg** —— SVG 可以内嵌 <script>，
 *    被浏览器当图片加载时照样执行，是最典型的存储型 XSS 载体；
 *  · `mimes` 才是真正的白名单，只放行 jpg / png。
 *
 * 另外还限制了下限尺寸：只卡上限的话，1×1 像素的图片也能通过，
 * 上传完成后界面上几乎什么都看不见，用户会以为上传失败了。
 */
class UploadAvatarRequest extends FormRequest
{
    public function rules(): array
    {
        $maxKilobytes = (int) config('identity.avatar.max_kilobytes', 2048);
        $mimes = implode(',', (array) config('identity.avatar.mimes', ['jpg', 'jpeg', 'png']));

        return [
            'avatar' => [
                'required',
                'file',
                'image',
                'mimes:'.$mimes,
                'max:'.$maxKilobytes,
                'dimensions:min_width=32,min_height=32,max_width=6000,max_height=6000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => '请选择一张图片。',
            'avatar.image' => '请上传 JPG 或 PNG 格式的图片。',
            'avatar.mimes' => '只支持 JPG 与 PNG；SVG 可能携带脚本，因此不予接受。',
            'avatar.max' => '图片不能超过 '.(int) config('identity.avatar.max_kilobytes', 2048).' KB。',
            'avatar.dimensions' => '图片尺寸需在 32×32 到 6000×6000 之间。',
        ];
    }
}
