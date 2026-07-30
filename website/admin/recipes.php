<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';
require_once __DIR__ . '/../配方/lib/recipes.php';

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    exit('Forbidden');
}

function admin_recipe_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_recipe_time(?string $value): string
{
    if (!$value) {
        return '-';
    }
    $time = strtotime($value);
    return $time === false ? $value : date('Y-m-d H:i', $time);
}

$csrf = auth_csrf_token();
$recipes = [];
$staticRecipes = [];
$error = '';
try {
    $recipes = recipe_db()->query('SELECT * FROM recipes ORDER BY updated_at DESC, id DESC')->fetchAll();
} catch (Throwable $exception) {
    error_log('Recipe admin list failed: ' . $exception->getMessage());
    $error = '配方数据库暂时不可用，请检查主库配置。';
}
try {
    $staticRecipes = array_values(array_filter(load_recipes(), static fn (array $recipe): bool => ($recipe['source_type'] ?? 'static') === 'static'));
} catch (Throwable $exception) {
    error_log('Static recipe admin list failed: ' . $exception->getMessage());
}
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title>配方管理 | 后台 | 示例服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260731a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260724a"></script>
    <script src="/assets/site.js?v=20260731a"></script>
</head>
<body class="admin-page machine-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= admin_recipe_h($csrf) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main machine-admin shell recipe-admin-list" data-csrf="<?= admin_recipe_h($csrf) ?>">
    <header class="machine-heading">
        <div>
            <a class="machine-back" href="/admin/">返回后台</a>
            <h1>配方管理</h1>
            <p>查看数据包静态配方，并维护主库 recipes 表中的自定义配方。</p>
        </div>
        <a class="machine-button primary" href="/admin/recipe_edit.php">新增配方</a>
    </header>

    <?php if ($error !== ''): ?><div class="admin-alert" role="alert"><p><?= admin_recipe_h($error) ?></p></div><?php endif; ?>

    <section class="machine-admin-section">
        <div class="machine-section-title"><h2>数据包静态配方</h2><span><?= count($staticRecipes) ?> 条</span></div>
        <div class="machine-admin-table recipe-admin-table">
            <table>
                <thead><tr><th>缩略图</th><th>配方名称</th><th>类型</th><th>输出物品</th><th>来源</th><th>状态</th></tr></thead>
                <tbody>
                <?php foreach ($staticRecipes as $recipe): ?>
                    <tr>
                        <td><img class="recipe-admin-thumb" src="/配方/<?= admin_recipe_h((string) $recipe['file']) ?>" alt="<?= admin_recipe_h((string) $recipe['title']) ?>" loading="lazy"></td>
                        <td><strong><?= admin_recipe_h((string) $recipe['title']) ?></strong></td>
                        <td>有序</td>
                        <td><code><?= admin_recipe_h((string) $recipe['result']) ?></code> × <?= (int) $recipe['count'] ?></td>
                        <td><code><?= admin_recipe_h((string) $recipe['source']) ?></code></td>
                        <td>随代码发布</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$staticRecipes): ?><tr><td colspan="6" class="recipe-admin-empty">没有数据包静态配方。</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="machine-admin-section">
        <div class="machine-section-title"><h2>数据库自定义配方</h2><span><?= count($recipes) ?> 条</span></div>
        <div class="machine-admin-table recipe-admin-table">
            <table>
                <thead><tr><th>缩略图</th><th>配方名称</th><th>类型</th><th>输出物品</th><th>更新时间</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($recipes as $recipe): ?>
                    <?php $id = (int) $recipe['id']; $thumb = recipe_public_thumbnail_url($id, (string) ($recipe['thumbnail'] ?? '')); ?>
                    <tr data-recipe-row="<?= $id ?>">
                        <td>
                            <?php if ($thumb !== ''): ?><img class="recipe-admin-thumb" src="<?= admin_recipe_h($thumb) ?>" alt="<?= admin_recipe_h((string) $recipe['name']) ?>" loading="lazy"><?php else: ?><span class="recipe-admin-thumb empty">无</span><?php endif; ?>
                        </td>
                        <td><strong><?= admin_recipe_h((string) $recipe['name']) ?></strong></td>
                        <td><?= (string) $recipe['type'] === 'shapeless' ? '无序' : '有序' ?></td>
                        <td><code><?= admin_recipe_h((string) $recipe['output_item_id']) ?></code> × <?= (int) $recipe['output_count'] ?></td>
                        <td><?= admin_recipe_h(admin_recipe_time((string) $recipe['updated_at'])) ?></td>
                        <td>
                            <a href="/admin/recipe_edit.php?id=<?= $id ?>">编辑</a>
                            <button class="machine-button danger recipe-delete-button" type="button" data-delete-id="<?= $id ?>" data-delete-name="<?= admin_recipe_h((string) $recipe['name']) ?>">删除</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$recipes): ?><tr><td colspan="6" class="recipe-admin-empty">还没有数据库配方。</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
<dialog class="recipe-confirm-dialog" id="recipe-delete-dialog">
    <form method="dialog" class="recipe-confirm-panel">
        <h2>删除配方</h2>
        <p>确定删除 <strong data-delete-name></strong> 吗？缩略图文件也会一并移除。</p>
        <div class="recipe-editor-actions">
            <button class="machine-button" value="cancel">取消</button>
            <button class="machine-button danger" value="delete" data-confirm-delete>删除</button>
        </div>
    </form>
</dialog>
<footer class="site-footer"><div class="shell"><span>示例服务器</span><div class="filing"></div></div></footer>
<script>
(function(){
    const root = document.querySelector('.recipe-admin-list');
    const dialog = document.querySelector('#recipe-delete-dialog');
    if (!root || !dialog) return;
    const nameTarget = dialog.querySelector('[data-delete-name]');
    const confirmButton = dialog.querySelector('[data-confirm-delete]');
    let activeId = 0;
    root.addEventListener('click', function(event) {
        const button = event.target.closest('[data-delete-id]');
        if (!button) return;
        activeId = Number(button.dataset.deleteId || 0);
        nameTarget.textContent = button.dataset.deleteName || '未命名配方';
        dialog.showModal();
    });
    dialog.addEventListener('close', async function() {
        if (dialog.returnValue !== 'delete' || !activeId) return;
        confirmButton.disabled = true;
        try {
            const response = await fetch('/admin/recipes_api.php?action=delete&id=' + encodeURIComponent(String(activeId)), {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({id: activeId, csrf_token: root.dataset.csrf || ''})
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.error || 'delete_failed');
            const row = root.querySelector('[data-recipe-row="' + activeId + '"]');
            if (row) row.remove();
        } catch (error) {
            showRecipeToast('删除失败，请稍后重试。', true);
        } finally {
            confirmButton.disabled = false;
            activeId = 0;
        }
    });
    function showRecipeToast(message, isError) {
        const toast = document.createElement('div');
        toast.className = 'plan-toast' + (isError ? ' error' : '');
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(function(){ toast.remove(); }, 2600);
    }
}());
</script>
</body>
</html>
