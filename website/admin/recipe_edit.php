<?php
declare(strict_types=1);

require_once __DIR__ . '/../统一认证/auth.php';
require_once __DIR__ . '/../配方/lib/recipes.php';

auth_require();
if (!auth_is_superadmin()) {
    http_response_code(403);
    exit('Forbidden');
}

function recipe_edit_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$id = max(0, (int) ($_GET['id'] ?? 0));
$recipe = null;
$error = '';
try {
    if ($id > 0) {
        $stmt = recipe_db()->prepare('SELECT * FROM recipes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $recipe = $stmt->fetch() ?: null;
        if (!$recipe) {
            $error = '配方不存在。';
        }
    } else {
        recipe_db();
    }
} catch (Throwable $exception) {
    error_log('Recipe editor load failed: ' . $exception->getMessage());
    $error = '配方数据库暂时不可用，请检查主库配置。';
}

$editorRecipe = null;
if ($recipe) {
    $editorRecipe = [
        'id' => (int) $recipe['id'],
        'name' => (string) $recipe['name'],
        'type' => (string) $recipe['type'],
        'input' => json_decode((string) $recipe['input'], true),
        'output' => ['itemId' => (string) $recipe['output_item_id'], 'count' => (int) $recipe['output_count']],
        'thumbnail_url' => recipe_public_thumbnail_url((int) $recipe['id'], (string) ($recipe['thumbnail'] ?? '')),
    ];
}

$data = [
    'csrf' => auth_csrf_token(),
    'recipe' => $editorRecipe,
    'items' => recipe_item_catalog(),
];
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive,nosnippet">
    <meta name="theme-color" content="#f9a8d4">
    <title><?= $id > 0 ? '编辑配方' : '新增配方' ?> | 后台 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="/assets/site.css?v=20260829a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="/assets/site.js?v=20260811a"></script>
</head>
<body class="admin-page machine-page recipe-editor-page">
<header class="topbar"><div class="shell"><a class="brand" href="/">Minecraft 生存服务器</a><nav class="nav" aria-label="站点导航"><a href="/">首页</a><a href="/状态/">实时状态</a><a href="/统计数据/">玩家统计</a><a href="/配方/">配方</a><a href="/附魔计算/">附魔计算</a><a href="/经纬度/">经纬度</a><a href="/计划表/">计划表</a><a class="nav-account" href="/admin/" aria-current="page">后台</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/"><input type="hidden" name="csrf_token" value="<?= recipe_edit_h($data['csrf']) ?>"><button type="submit">退出</button></form></nav></div></header>
<main class="machine-main shell">
    <header class="machine-heading">
        <div>
            <a class="machine-back" href="/admin/recipes.php">返回配方管理</a>
            <h1><?= $id > 0 ? '编辑配方' : '新增配方' ?></h1>
            <p>使用工作台面板维护配方，并由浏览器生成合成表缩略图。</p>
        </div>
        <div class="machine-heading-actions">
            <button class="machine-button" type="button" data-generate-thumbnail <?= $recipe ? '' : 'disabled' ?>>生成缩略图</button>
            <button class="machine-button primary" type="button" data-save-recipe>保存配方</button>
        </div>
    </header>

    <?php if ($error !== ''): ?><div class="admin-alert" role="alert"><p><?= recipe_edit_h($error) ?></p></div><?php endif; ?>

    <section class="recipe-editor" data-recipe-editor>
        <section class="recipe-workbench-panel">
            <div class="recipe-editor-fields">
                <label><span>配方名称</span><input type="text" maxlength="255" data-recipe-name placeholder="留空时使用输出物品名称"></label>
                <label><span>放入数量</span><input type="number" min="1" max="999" step="1" value="1" data-selected-count></label>
                <label><span>输出数量</span><input type="number" min="1" max="999" step="1" value="1" data-output-count></label>
            </div>
            <div class="recipe-mode-tabs" role="tablist" aria-label="配方类型">
                <button type="button" data-mode="shaped" class="active">有序配方</button>
                <button type="button" data-mode="shapeless">无序配方</button>
            </div>
            <div class="recipe-crafting-gui" aria-label="Minecraft 工作台">
                <div class="recipe-grid-3x3" data-input-grid></div>
                <div class="recipe-arrow" aria-hidden="true"></div>
                <div class="recipe-output-wrap">
                    <div class="recipe-slot output" data-output-slot data-slot-kind="output" tabindex="0" aria-label="输出格"></div>
                    <span>输出</span>
                </div>
            </div>
            <div class="recipe-editor-actions">
                <button class="machine-button" type="button" data-clear-slot>清除选中格</button>
                <button class="machine-button" type="button" data-clear-all>清空工作台</button>
            </div>
            <div class="recipe-thumbnail-preview">
                <span>缩略图预览</span>
                <img data-thumbnail-preview alt="配方缩略图预览" hidden>
                <div data-thumbnail-empty>保存后自动生成缩略图</div>
            </div>
        </section>

        <aside class="recipe-item-panel">
            <div class="recipe-item-search">
                <label><span>物品搜索</span><input type="search" autocomplete="off" data-item-search placeholder="搜索名称或 ID"></label>
                <button class="machine-button" type="button" data-clear-selection>取消选择</button>
            </div>
            <div class="recipe-selected-item" data-selected-item>未选择物品</div>
            <div class="recipe-item-grid" data-item-grid></div>
        </aside>
    </section>
</main>
<footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
<script>window.RecipeEditorData = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="/admin/assets/recipe-editor.js?v=20260826external3"></script>
</body>
</html>
