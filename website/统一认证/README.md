# 统一认证接入

同一站点下的新功能只需要引用认证核心，所有模块共享 `mc_machine_session`、用户表、CSRF、登录限流和审计记录。

```php
require_once __DIR__ . '/../统一认证/auth.php';

auth_require();
$user = auth_current_user();
$csrf = auth_csrf_token();
```

公开页面可使用 `auth_is_authenticated()` 判断状态。需要认证时跳转：

```php
header('Location: ' . auth_login_url('/新功能/目标页面.php'));
exit;
```

退出必须使用 POST：

```php
<form method="post" action="/统一认证/logout.php">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(auth_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <button type="submit">退出</button>
</form>
```

`next` 只接受本站根路径，外部 URL、反斜杠和目录回退会被拒绝。
