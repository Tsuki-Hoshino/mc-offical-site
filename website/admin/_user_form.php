<?php
$account = $account ?? [];
$isEdit = !empty($account['id']);
?>
<?php if ($errors): ?><div class="machine-alert" role="alert"><strong>请修正以下问题</strong><ul><?php foreach ($errors as $error): ?><li><?= admin_h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form class="machine-account-form" method="post" action="<?= admin_h($action) ?>">
    <input type="hidden" name="csrf_token" value="<?= admin_h(auth_csrf_token()) ?>">
    <label><span>账户名</span><input type="text" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_.-]{3,32}" autocomplete="off" value="<?= admin_h((string) ($_POST['username'] ?? $account['username'] ?? '')) ?>"><small>3-32 位，仅限字母、数字、下划线、点和连字符</small></label>
    <label><span>角色</span><select name="role"><option value="editor" <?= ($_POST['role'] ?? $account['role'] ?? 'editor') === 'editor' ? 'selected' : '' ?>>编辑账户</option><option value="superadmin" <?= ($_POST['role'] ?? $account['role'] ?? '') === 'superadmin' ? 'selected' : '' ?>>超级管理员</option></select><small>编辑账户具备业务管理权限；后台和账户管理仅超级管理员可进入</small></label>
    <label><span><?= $isEdit ? '新密码' : '密码' ?></span><input type="password" name="password" <?= $isEdit ? '' : 'required' ?> minlength="6" maxlength="128" autocomplete="new-password"><small>6-128 位，必须包含数字、大写字母、小写字母和特殊符号<?= $isEdit ? '；留空则保持原密码' : '' ?></small></label>
    <label><span>确认密码</span><input type="password" name="password_confirm" <?= $isEdit ? '' : 'required' ?> minlength="6" maxlength="128" autocomplete="new-password"></label>
    <?php if ($isEdit): ?><label class="machine-account-toggle"><input type="checkbox" name="enabled" value="1" <?= isset($_POST['username']) ? (!empty($_POST['enabled']) ? 'checked' : '') : ((int) $account['enabled'] === 1 ? 'checked' : '') ?>><span>允许该账户登录和写入</span></label><?php endif; ?>
    <div class="machine-form-actions"><a class="machine-button" href="/admin/users.php">取消</a><button class="machine-button primary" type="submit"><?= $isEdit ? '保存账户' : '创建账户' ?></button></div>
</form>
