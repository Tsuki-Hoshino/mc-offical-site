<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$q = trim((string) ($_GET['q'] ?? ''));
$dimension = (string) ($_GET['dimension'] ?? '');
$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(machine_name LIKE :q OR player_name LIKE :q OR manual_text LIKE :q OR notes_text LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if ($dimension !== '' && array_key_exists($dimension, dimensions())) {
    $where[] = 'dimension = :dimension';
    $params['dimension'] = $dimension;
}

$sql = 'SELECT * FROM machines' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
$sql .= ' ORDER BY updated_at DESC, id DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$machines = $stmt->fetchAll();
$countByDimension = array_fill_keys(array_keys(dimensions()), 0);
foreach ($machines as $item) {
    $countByDimension[$item['dimension']]++;
}

render_site_header('经纬度', '登记服务器建筑机器，查询维度与坐标，并快速换算主世界和地狱坐标。');
?>
<main class="machine-main shell">
    <header class="machine-heading">
        <div>
            <h1>经纬度</h1>
            <p>机器坐标登记与维度换算</p>
        </div>
        <?php if (machine_is_authenticated()): ?>
            <a class="machine-button primary" href="create.php">新增登记</a>
        <?php else: ?>
            <a class="machine-button" href="<?= h(auth_login_url('/经纬度/')) ?>">统一认证</a>
        <?php endif; ?>
    </header>

    <section class="machine-toolbar" aria-label="筛选机器">
        <form method="get" action="index.php" role="search">
            <label class="machine-search">
                <span>搜索</span>
                <input type="search" name="q" value="<?= h($q) ?>" placeholder="机器名称、玩家或说明">
            </label>
            <label>
                <span>维度</span>
                <select name="dimension">
                    <option value="">全部维度</option>
                    <?php foreach (dimensions() as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $dimension === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="machine-button primary" type="submit">筛选</button>
            <?php if ($q !== '' || $dimension !== ''): ?><a class="machine-button" href="index.php">清除</a><?php endif; ?>
        </form>
        <div class="machine-counts" aria-label="当前结果统计">
            <?php foreach (dimensions() as $value => $label): ?>
                <span><?= h($label) ?> <strong><?= $countByDimension[$value] ?></strong></span>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if (!$machines): ?>
        <section class="machine-empty">
            <h2><?= $q !== '' || $dimension !== '' ? '没有匹配的机器' : '还没有登记机器' ?></h2>
            <p><?= $q !== '' || $dimension !== '' ? '调整搜索词或维度后再试。' : '记录第一台机器的用途、坐标和操作说明。' ?></p>
            <a class="machine-button primary" href="create.php">新增登记</a>
        </section>
    <?php else: ?>
        <section class="machine-list" aria-label="机器登记列表">
            <?php foreach ($machines as $machine): ?>
                <article class="machine-row">
                    <a class="machine-row-main" href="show.php?id=<?= (int) $machine['id'] ?>">
                        <span class="dimension-mark <?= h($machine['dimension']) ?>" aria-hidden="true"></span>
                        <span class="machine-row-copy">
                            <strong><?= h($machine['machine_name']) ?></strong>
                            <small><?= h($machine['player_name']) ?> · <?= h(dimension_name($machine['dimension'])) ?></small>
                        </span>
                        <span class="coordinate-block">
                            <small>原坐标</small>
                            <code><?= h(format_coordinates($machine['x'], $machine['y'], $machine['z'])) ?></code>
                        </span>
                        <span class="coordinate-arrow" aria-hidden="true">→</span>
                        <span class="coordinate-block converted">
                            <small><?= $machine['converted_dimension'] ? h(dimension_name($machine['converted_dimension'])) : '无需换算' ?></small>
                            <code><?= $machine['converted_dimension'] ? h(format_coordinates($machine['converted_x'], $machine['converted_y'], $machine['converted_z'])) : '末地' ?></code>
                        </span>
                    </a>
                    <span class="machine-row-time">更新于 <?= h(display_time($machine['updated_at'])) ?></span>
                    <?php if (machine_is_authenticated()): ?>
                        <span class="machine-row-actions">
                            <a href="edit.php?id=<?= (int) $machine['id'] ?>">编辑</a>
                            <a class="danger-link" href="delete.php?id=<?= (int) $machine['id'] ?>">删除</a>
                        </span>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>
<?php render_site_footer(); ?>
