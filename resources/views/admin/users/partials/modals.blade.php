{{--
    账号管理的三个弹窗。抽成 partial 是因为列表页与详情页共用同一套动作按钮，
    两处必须弹出完全一样的表单 —— 否则「同一个操作在两个入口行为不同」。
--}}

{{-- ============================ 新建 / 编辑 ============================ --}}
<div class="modal-mask" id="user-modal" data-mode="create">
    <div class="modal">
        <div class="modal__head" id="user-modal-title" data-en="Create">新建账号</div>

        <form id="user-form" class="modal__body" autocomplete="off" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label for="uf-name">登录名 <span class="faint">*</span></label>
                    <input type="text" id="uf-name" name="name" maxlength="60" required>
                </div>
                <div class="field">
                    <label for="uf-display">显示名</label>
                    <input type="text" id="uf-display" name="display_name" maxlength="60"
                           placeholder="留空则使用登录名">
                </div>
            </div>

            <div class="field">
                <label for="uf-email">邮箱 <span class="faint">*</span></label>
                <input type="email" id="uf-email" name="email" maxlength="190" required>
            </div>

            {{-- 仅新建时出现：编辑走「重置密码」这个独立动作，避免密码被无意改动 --}}
            <div class="field" data-only="create">
                <label for="uf-password">初始密码</label>
                <input type="text" id="uf-password" name="password" minlength="8" maxlength="72"
                       placeholder="留空则由系统生成随机密码">
                <div class="faint small" style="margin-top:5px">
                    建议留空：系统生成的密码强度有保证，且会在保存后一次性展示。
                </div>
            </div>

            <div class="form-grid">
                <div class="field">
                    <label for="uf-role">角色 <span class="faint">*</span></label>
                    {{-- 直接读枚举而不是依赖传入变量：partial 被列表页与详情页共用，
                         少一个隐式契约就少一处「某个页面忘了传参导致下拉框空掉」 --}}
                    <select id="uf-role" name="role" required>
                        @foreach (\App\Enums\UserRole::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="uf-active">账号状态</label>
                    <label class="check" style="padding-top:7px">
                        <input type="checkbox" id="uf-active" name="is_active" value="1">
                        允许登录
                    </label>
                </div>
            </div>

            <div class="field" style="margin-bottom:0">
                <label class="check">
                    <input type="checkbox" id="uf-scope" name="strict_source_scope" value="1">
                    限制出处范围
                </label>
                <div class="faint small" style="margin-top:5px">
                    开启后该编辑者只能修改自己负责的出处相关条目；默认开启。
                </div>
            </div>
        </form>

        <div class="modal__foot">
            <button class="btn" type="button" data-close-modal>取消</button>
            <button class="btn btn--primary" type="submit" form="user-form" id="user-submit">保存</button>
        </div>
    </div>
</div>

{{-- ============================ 密码一次性展示 ============================ --}}
<div class="modal-mask" id="password-modal">
    <div class="modal" style="width:min(540px, 96vw)">
        <div class="modal__head" data-en="Password">新密码已生成</div>

        <div class="modal__body">
            <div class="alert alert--warn">
                该密码<strong>只显示这一次</strong>。关闭后无法再次查看，请立即通过可信渠道转达给本人。
            </div>

            <div class="password-reveal" id="password-value">—</div>

            <div class="btn-row" style="margin-top:12px">
                <button class="btn btn--sm" type="button" id="copy-password">复制密码</button>
                <span class="faint small" id="password-target"></span>
            </div>
        </div>

        <div class="modal__foot">
            <button class="btn btn--primary" type="button" data-close-modal data-after-close="reload">我已记录</button>
        </div>
    </div>
</div>

{{-- ============================ 批量结果 ============================ --}}
<div class="modal-mask" id="bulk-modal">
    <div class="modal" style="width:min(620px, 96vw)">
        <div class="modal__head" data-en="Bulk Result">批量操作结果</div>

        <div class="modal__body">
            <p id="bulk-summary" style="margin-top:0"></p>
            <div id="bulk-skipped"></div>
        </div>

        <div class="modal__foot">
            <button class="btn btn--primary" type="button" data-close-modal data-after-close="reload">知道了</button>
        </div>
    </div>
</div>
