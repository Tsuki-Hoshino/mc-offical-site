<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/recipes.php';

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$recipes = search_recipes($query);
$total = count(load_recipes());
$resultCount = count($recipes);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta name="robots" content="noindex,nofollow,noarchive,nosnippet,noimageindex">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f9a8d4">
    <title>合成表 | Minecraft 生存服务器</title>
    <link rel="stylesheet" href="../assets/site.css?v=20260829a">
    <script src="/assets/lenis.min.js?v=1.3.25"></script>
    <script src="/assets/site-config.php?v=20260829b"></script>
    <script src="../assets/site.js?v=20260811a"></script>
    <script defer src="assets/app.js?v=20260829c"></script>
</head>
<body class="recipe-page">
    <header class="topbar">
        <div class="shell recipe-topbar">
            <a class="brand" href="../">Minecraft 生存服务器</a>
<nav class="nav" aria-label="站点导航">
                <a href="../">首页</a>
                <a href="../状态/">实时状态</a>
                <a href="../统计数据/">玩家统计</a>
                <a href="./" aria-current="page">配方</a>
                <a href="../附魔计算/">附魔计算</a>
                <a href="../经纬度/">经纬度</a>
            <a href="../计划表/">计划表</a></nav>
        </div>
    </header>

    <main>
        <div class="recipe-page-heading">
            <h1>合成表搜索</h1>
            <p><span id="result-count"><?= h((string) $resultCount) ?></span> / <?= h((string) $total) ?> 个配方</p>
        </div>
        <div class="recipe-search-band">
            <form class="search" method="get" action="index.php" role="search">
                <div class="moe-search" id="moe-search">
                    <span class="moe-search-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>
                        </svg>
                    </span>
                    <input class="moe-search-input" id="q" name="q" type="search" value="<?= h($query) ?>" placeholder=" " autocomplete="off" aria-label="搜索配方">
                    <div class="moe-search-suggestions" aria-hidden="true"><span id="search-suggestion">中文：深色橡木栅栏门</span></div>
                    <div class="moe-search-panel" id="moe-search-panel" hidden></div>
                </div>
            </form>
        </div>
        <div class="empty" id="empty-state"<?= $resultCount > 0 ? ' hidden' : '' ?>>
            没有匹配的配方。
        </div>
        <section class="recipe-grid" id="recipe-grid" aria-live="polite">
            <?php foreach ($recipes as $recipe): ?>
                <article class="recipe-card">
                    <?php if (($recipe['file'] ?? '') !== ''): ?>
                        <a class="image-link" href="<?= h($recipe['file']) ?>" aria-haspopup="dialog" data-title="<?= h($recipe['title']) ?>" data-result="<?= h($recipe['result']) ?>" data-count="<?= h((string) $recipe['count']) ?>" data-pack="<?= h($recipe['pack'] !== '' ? $recipe['pack'] : 'unknown') ?>" data-materials="<?= h(json_encode($recipe['materials'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
                            <img src="<?= h($recipe['file']) ?>" alt="<?= h($recipe['title']) ?>" loading="lazy">
                        </a>
                    <?php else: ?>
                        <div class="recipe-image-placeholder" aria-hidden="true"><span><?= h(mb_substr((string) $recipe['title'], 0, 2)) ?></span></div>
                    <?php endif; ?>
                    <div class="recipe-meta">
                        <h2><?= h($recipe['title']) ?></h2>
                        <code><?= h($recipe['result']) ?></code>
                        <div class="subline">
                            <span><?= h($recipe['pack'] !== '' ? $recipe['pack'] : 'unknown') ?></span>
                            <span>数量 <?= h((string) $recipe['count']) ?></span>
                            <?php if (($recipe['recipe_type_label'] ?? '') !== ''): ?><span><?= h($recipe['recipe_type_label']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
    <dialog class="recipe-viewer" id="recipe-viewer" aria-labelledby="recipe-viewer-title">
        <div class="recipe-viewer-bar">
            <strong id="recipe-viewer-title">配方大图</strong>
            <button class="recipe-viewer-close" id="recipe-viewer-close" type="button" aria-label="关闭大图" title="关闭大图">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <div class="recipe-viewer-body" id="recipe-viewer-body">
            <div class="recipe-viewer-stage" id="recipe-viewer-stage">
                <img id="recipe-viewer-image" src="" alt="" draggable="false">
            </div>
            <section class="recipe-viewer-details" aria-label="配方材料">
                <div class="recipe-viewer-summary">
                    <div><span>产物</span><strong id="recipe-viewer-result">-</strong></div>
                    <div><span>产出</span><strong id="recipe-viewer-count">-</strong></div>
                    <div><span>来源</span><strong id="recipe-viewer-pack">-</strong></div>
                </div>
                <div class="recipe-viewer-materials">
                    <strong>所需材料</strong>
                    <ul id="recipe-viewer-material-list"></ul>
                </div>
            </section>
        </div>
    </dialog>    <footer class="site-footer"><div class="shell"><span>Minecraft 生存服务器</span><div class="filing"><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"></a><a href="https://beian.mps.gov.cn/#/query/webSearch?code=" target="_blank" rel="noopener noreferrer"></a></div></div></footer>
</body>
</html>
