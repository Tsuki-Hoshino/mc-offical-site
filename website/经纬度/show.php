<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$id = (int) ($_GET['id'] ?? 0);
$machine = find_machine($id);
if (!$machine) {
    http_response_code(404);
    render_site_header('记录不存在');
    echo '<main class="machine-main shell"><section class="machine-empty"><h1>登记记录不存在</h1><a class="machine-button" href="index.php">返回列表</a></section></main>';
    render_site_footer();
    exit;
}
render_site_header($machine['machine_name']);
?>
<main class="machine-main machine-detail shell">
    <header class="machine-heading">
        <div>
            <a class="machine-back" href="index.php">返回经纬度</a>
            <h1><?= h($machine['machine_name']) ?></h1>
            <p>由 <?= h($machine['player_name']) ?> 登记 · 更新于 <?= h(display_time($machine['updated_at'])) ?></p>
        </div>
        <div class="machine-heading-actions">
            <?php if (machine_is_authenticated()): ?>
                <a class="machine-button" href="edit.php?id=<?= $id ?>">编辑</a><a class="machine-button danger" href="delete.php?id=<?= $id ?>">删除</a>
            <?php else: ?>
                <a class="machine-button" href="<?= h(auth_login_url('/经纬度/')) ?>">统一认证</a>
            <?php endif; ?>
        </div>
    </header>

    <section class="machine-coordinate-hero">
        <div>
            <span><?= h(dimension_name($machine['dimension'])) ?></span>
            <code><?= h(format_coordinates($machine['x'], $machine['y'], $machine['z'])) ?></code>
            <small>X / Y / Z</small>
        </div>
        <span class="coordinate-arrow" aria-hidden="true">→</span>
        <div class="converted">
            <?php if ($machine['converted_dimension']): ?>
                <span><?= h(dimension_name($machine['converted_dimension'])) ?></span>
                <code><?= h(format_coordinates($machine['converted_x'], $machine['converted_y'], $machine['converted_z'])) ?></code>
                <small>换算坐标</small>
            <?php else: ?>
                <span>末地</span><code>无需换算</code><small>独立坐标系</small>
            <?php endif; ?>
        </div>
    </section>

    <div class="machine-detail-grid">
        <div class="machine-detail-copy">
            <section><h2>机器实用手册</h2><?php if (trim((string) $machine['manual_text']) !== ''): ?><div class="machine-prose"><?= nl2br(h($machine['manual_text'])) ?></div><?php else: ?><p class="machine-muted">暂无手册文字。</p><?php endif; ?><?php if ($machine['manual_file_path']): ?><a class="machine-file-link" href="<?= machine_file_url($id, 'manual') ?>">下载手册文件</a><?php endif; ?></section>
            <section><h2>注意事项</h2><?php if (trim((string) $machine['notes_text']) !== ''): ?><div class="machine-prose"><?= nl2br(h($machine['notes_text'])) ?></div><?php else: ?><p class="machine-muted">暂无注意事项。</p><?php endif; ?><?php if ($machine['notes_file_path']): ?><a class="machine-file-link" href="<?= machine_file_url($id, 'notes') ?>">下载注意事项文件</a><?php endif; ?></section>
        </div>
        <aside class="machine-photo">
            <h2>机器照片</h2>
            <?php if ($machine['photo_path']): ?><a href="<?= machine_file_url($id, 'photo') ?>" target="_blank" rel="noopener"><img src="<?= machine_file_url($id, 'photo') ?>" alt="<?= h($machine['machine_name']) ?> 机器照片"></a><?php else: ?><div class="machine-photo-empty">暂无照片</div><?php endif; ?>
            <dl><div><dt>创建时间</dt><dd><?= h(display_time($machine['created_at'])) ?></dd></div><div><dt>记录编号</dt><dd>#<?= $id ?></dd></div></dl>
        </aside>
    </div>
</main>
<?php render_site_footer(); ?>
