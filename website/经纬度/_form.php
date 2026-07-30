<?php
declare(strict_types=1);
$machine = $machine ?? [];
$action = $action ?? '';
$submitLabel = $submitLabel ?? '保存';

function field_value(array $machine, string $field): string
{
    return isset($_POST[$field]) ? (string) $_POST[$field] : (isset($machine[$field]) ? (string) $machine[$field] : '');
}
?>
<?php if (!empty($errors)): ?>
    <div class="machine-alert" role="alert">
        <strong>请修正以下问题</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= h((string) $error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form class="machine-form" method="post" action="<?= h($action) ?>" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <section class="machine-form-section">
        <div class="machine-section-head"><span>01</span><div><h2>基本信息</h2><p>用于在列表中快速识别这台机器</p></div></div>
        <div class="machine-fields two">
            <label><span>机器名称</span><input type="text" name="machine_name" required maxlength="120" autocomplete="off" value="<?= h(field_value($machine, 'machine_name')) ?>" placeholder="例如：西北区刷铁机"></label>
            <label><span>登记玩家</span><input type="text" name="player_name" required maxlength="64" autocomplete="username" value="<?= h(field_value($machine, 'player_name')) ?>" placeholder="游戏内名称"></label>
        </div>
    </section>

    <section class="machine-form-section">
        <div class="machine-section-head"><span>02</span><div><h2>位置坐标</h2><p>主世界与地狱会自动按 8:1 换算</p></div></div>
        <div class="machine-fields coordinates">
            <label><span>所在维度</span><select name="dimension" id="machine-dimension" required><?php foreach (dimensions() as $value => $label): ?><?php $selected = field_value($machine, 'dimension') === $value; ?><option value="<?= h($value) ?>" <?= $selected ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label>
            <?php foreach (['x' => 'X', 'y' => 'Y', 'z' => 'Z'] as $field => $label): ?>
                <label><span><?= $label ?> 坐标</span><input id="machine-<?= $field ?>" type="number" name="<?= $field ?>" step="any" required value="<?= h(field_value($machine, $field)) ?>" placeholder="0"></label>
            <?php endforeach; ?>
        </div>
        <div class="coordinate-preview" id="coordinate-preview" aria-live="polite">
            <span>换算坐标</span><strong id="coordinate-preview-dimension">地狱</strong><code id="coordinate-preview-value">等待输入坐标</code>
        </div>
    </section>

    <section class="machine-form-section">
        <div class="machine-section-head"><span>03</span><div><h2>照片与文档</h2><p>单个文件不超过 10 MB</p></div></div>
        <div class="machine-upload-grid">
            <label class="machine-upload"><span>机器照片</span><input type="file" name="photo" accept=".jpg,.jpeg,.png,.gif,.webp,image/*" data-image-compress><small data-file-label>选择图片</small></label>
            <label class="machine-upload"><span>手册文件</span><input type="file" name="manual_file" accept=".pdf,.txt,.md,.markdown,.jpg,.jpeg,.png,.gif,.webp,image/*" data-image-compress><small data-file-label>选择文件</small></label>
            <label class="machine-upload"><span>注意事项文件</span><input type="file" name="notes_file" accept=".pdf,.txt,.md,.markdown,.jpg,.jpeg,.png,.gif,.webp,image/*" data-image-compress><small data-file-label>选择文件</small></label>
        </div>
        <?php if (!empty($machine['photo_path']) || !empty($machine['manual_file_path']) || !empty($machine['notes_file_path'])): ?>
            <div class="machine-existing-files">
                <?php foreach (['photo' => ['照片', 'photo_path'], 'manual_file' => ['手册文件', 'manual_file_path'], 'notes_file' => ['注意事项文件', 'notes_file_path']] as $field => [$label, $path]): ?>
                    <?php $fileType = $field === 'manual_file' ? 'manual' : ($field === 'notes_file' ? 'notes' : 'photo'); ?>
                    <?php if (!empty($machine[$path])): ?><label><input type="checkbox" name="remove_<?= h($field) ?>" value="1"><span>删除当前<?= h($label) ?></span><a href="<?= machine_file_url((int) $machine['id'], $fileType) ?>" target="_blank" rel="noopener">查看</a></label><?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="machine-form-section">
        <div class="machine-section-head"><span>04</span><div><h2>使用说明</h2><p>写清启动、关闭与维护方式</p></div></div>
        <div class="machine-fields two textareas">
            <label><span>机器实用手册</span><textarea name="manual_text" rows="8" placeholder="操作步骤、补货位置、产能等"><?= h(field_value($machine, 'manual_text')) ?></textarea></label>
            <label><span>注意事项</span><textarea name="notes_text" rows="8" placeholder="危险区域、挂机要求、常见故障等"><?= h(field_value($machine, 'notes_text')) ?></textarea></label>
        </div>
    </section>

    <div class="machine-form-actions">
        <a class="machine-button" href="<?= !empty($machine['id']) ? 'show.php?id=' . (int) $machine['id'] : 'index.php' ?>">取消</a>
        <button class="machine-button primary" type="submit"><?= h($submitLabel) ?></button>
    </div>
</form>
