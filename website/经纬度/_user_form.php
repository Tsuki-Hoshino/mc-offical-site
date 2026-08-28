<?php
$account = $account ?? [];
$isEdit = !empty($account['id']);
?>
<?php if ($errors): ?><div class="machine-alert" role="alert"><strong>请修正以下问题</strong><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="machine-account-form" method="post" action="<?= h($action) ?>">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <label><span>账户名</span><input type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.-]{3,32}" autocomplete="off" value="<?= h((string) ($_POST['username'] ?? $account['username'] ?? '')) ?>"><small>3–32 位，仅限字母、数字、下划线、点和连字符</small></label>
    <label><span>角色</span><select name="role" <?= $isEdit && !machine_is_superadmin() ? 'disabled' : '' ?>><option value="editor" <?= ($_POST['role'] ?? $account['role'] ?? 'editor') === 'editor' ? 'selected' : '' ?>>编辑账户</option><option value="superadmin" <?= ($_POST['role'] ?? $account['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>超级管理员</option></select><?php if ($isEdit && !machine_is_superadmin()): ?><input type="hidden" name="role" value="<?= h((string) ($account['role'] ?? 'editor')) ?>"><?php endif; ?><small>编辑账户具备站点管理权限；只有超级管理员可以新增账户和调整角色</small></label>
    <label><span><?= $isEdit ? '新密码' : '密码' ?></span><input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6" maxlength="128" autocomplete="new-password"><small>6-128 位，必须包含数字、大写字母、小写字母和特殊符号<?= $isEdit ? '；留空则保持原密码' : '' ?></small></label>
    <label><span>确认密码</span><input type="password" name="password_confirm" <?= $isEdit ? '' : 'required' ?> minlength="6" maxlength="128" autocomplete="new-password"></label>
    <?php if ($isEdit): ?><label class="machine-account-toggle"><input type="checkbox" name="enabled" value="1" <?= isset($_POST['username']) ? (!empty($_POST['enabled']) ? 'checked' : '') : ((int) $account['enabled'] === 1 ? 'checked' : '') ?>><span>允许该账户登录和写入</span></label><?php endif; ?>
    <div class="machine-form-actions"><a class="machine-button" href="users.php">取消</a><button class="machine-button primary" type="submit"><?= $isEdit ? '保存账户' : '创建账户' ?></button></div>
</form>
