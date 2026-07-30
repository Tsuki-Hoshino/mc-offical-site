<?php
declare(strict_types=1);

function recipe_csv_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'index.csv';
}

function recipe_root_path(): string
{
    return dirname(__DIR__);
}

function recipe_project_root_path(): string
{
    return dirname(__DIR__, 2);
}

function recipe_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    require_once recipe_project_root_path() . DIRECTORY_SEPARATOR . '统一认证' . DIRECTORY_SEPARATOR . 'main_database.php';
    $pdo = site_main_pdo();
    recipe_initialize_database($pdo);
    return $pdo;
}

function recipe_initialize_database(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS recipes (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            type ENUM('shaped','shapeless') NOT NULL,
            input MEDIUMTEXT NOT NULL,
            output_item_id VARCHAR(255) NOT NULL,
            output_count INT UNSIGNED NOT NULL DEFAULT 1,
            thumbnail VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_recipes_updated (updated_at),
            INDEX idx_recipes_output (output_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function read_recipe_csv_row($handle)
{
    return fgetcsv($handle, 0, ',', '"', '\\');
}

function normalize_csv_headers(array $headers): array
{
    $normalized = [];
    foreach ($headers as $header) {
        $normalized[] = trim(str_replace("\xEF\xBB\xBF", '', (string) $header));
    }

    return $normalized;
}

function has_required_csv_headers(array $headers): bool
{
    foreach (['Number', 'File', 'Source', 'Result', 'Count'] as $required) {
        if (!in_array($required, $headers, true)) {
            return false;
        }
    }

    return true;
}

function normalize_search_text(string $value): string
{
    $value = strtolower($value);
    $value = str_replace('minecraft:', 'minecraft ', $value);
    $value = preg_replace('/\.[a-z0-9]+$/', '', $value) ?? $value;

    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
    if ($normalized === null) {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    }

    return trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
}

function compact_search_text(string $value): string
{
    return str_replace(' ', '', normalize_search_text($value));
}

function search_starts_with(string $haystack, string $needle): bool
{
    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
}

function search_contains(string $haystack, string $needle): bool
{
    return $needle === '' || strpos($haystack, $needle) !== false;
}

function words_from_text(string $value): array
{
    $normalized = normalize_search_text($value);
    if ($normalized === '') {
        return [];
    }

    return preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
}

function initials_from_words(array $words): string
{
    $initials = '';
    foreach ($words as $word) {
        $initials .= $word[0] ?? '';
    }

    return $initials;
}

function zh_initials(string $value): string
{
    $map = [
        '金' => 'j', '合' => 'h', '欢' => 'h', '木' => 'm', '船' => 'c', '运' => 'y', '输' => 's',
        '门' => 'm', '栅' => 'z', '栏' => 'l', '压' => 'y', '力' => 'l', '板' => 'b', '告' => 'g',
        '示' => 's', '牌' => 'p', '台' => 't', '阶' => 'j', '楼' => 'l', '梯' => 't', '活' => 'h',
        '白' => 'b', '桦' => 'h', '竹' => 'z', '筏' => 'f', '马' => 'm', '赛' => 's', '克' => 'k',
        '桶' => 't', '基' => 'j', '岩' => 'y', '安' => 'a', '山' => 's', '黑' => 'h', '石' => 's',
        '骨' => 'g', '块' => 'k', '弓' => 'g', '碗' => 'w', '面' => 'm', '包' => 'b', '砖' => 'z',
        '炼' => 'l', '药' => 'y', '锅' => 'g', '紫' => 'z', '水' => 's', '晶' => 'j', '母' => 'm',
        '樱' => 'y', '花' => 'h', '箱' => 'x', '时' => 's', '钟' => 'z', '深' => 's', '圆' => 'y',
        '命' => 'm', '令' => 'l', '方' => 'f', '指' => 'z', '南' => 'n', '针' => 'z', '堆' => 'd',
        '肥' => 'f', '绯' => 'f', '红' => 'h', '切' => 'q', '制' => 'z', '铜' => 't', '砂' => 's',
        '色' => 's', '橡' => 'x', '海' => 'h', '末' => 'm', '地' => 'd', '传' => 'c', '送' => 's',
        '框' => 'k', '架' => 'j', '斑' => 'b', '驳' => 'b', '熔' => 'r', '炉' => 'l', '矿' => 'k',
        '车' => 'c', '闪' => 's', '烁' => 's', '西' => 'x', '瓜' => 'g', '片' => 'p', '胡' => 'h',
        '萝' => 'l', '卜' => 'b', '岗' => 'g', '重' => 'z', '质' => 'z', '测' => 'c', '漏' => 'l',
        '斗' => 'd', '铁' => 't', '锁' => 's', '链' => 'l', '丛' => 'c', '林' => 'l', '灯' => 'd',
        '笼' => 'l', '轻' => 'q', '避' => 'b', '雷' => 'l', '树' => 's', '苔' => 't', '泥' => 'n',
        '下' => 'x', '界' => 'j', '云' => 'y', '杉' => 's', '氧' => 'y', '化' => 'h', '猪' => 'z',
        '灵' => 'l', '刷' => 's', '怪' => 'g', '蛋' => 'd', '掠' => 'l', '夺' => 'd', '者' => 'z',
        '磨' => 'm', '光' => 'g', '英' => 'y', '平' => 'p', '滑' => 'h', '魂' => 'h', '火' => 'h',
        '把' => 'b', '棍' => 'g', '凝' => 'n', '灰' => 'h', '村' => 'c', '民' => 'm', '诡' => 'g',
        '异' => 'y', '涂' => 't', '蜡' => 'l', '锈' => 'x', '蚀' => 's', '风' => 'f', '急' => 'j',
        '迫' => 'p', '珀' => 'p', '精' => 'j', '确' => 'q', '采' => 'c', '集' => 'j', '脉' => 'm',
    ];

    $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false) {
        return '';
    }

    $initials = '';
    foreach ($chars as $char) {
        if (isset($map[$char])) {
            $initials .= $map[$char];
        } elseif (preg_match('/[a-z0-9]/i', $char) === 1) {
            $initials .= strtolower($char);
        }
    }

    return $initials;
}

function add_aliases(array &$aliases, array $items)
{
    foreach ($items as $item) {
        $item = trim((string) $item);
        if ($item !== '') {
            $aliases[] = $item;
        }
    }
}

function humanize_recipe_id(string $result): string
{
    $id = preg_replace('/^[a-z0-9_.-]+:/', '', $result) ?? $result;
    $id = str_replace('_', ' ', $id);
    return ucwords($id);
}

function recipe_pack_from_source(string $source): string
{
    if (preg_match('#^data/([^/]+)/#', $source, $matches) === 1) {
        return $matches[1];
    }

    return '';
}

function recipe_group_from_source(string $source): string
{
    if (preg_match('#/recipe/([^/]+)/#', $source, $matches) === 1) {
        return $matches[1];
    }

    return '';
}

function recipe_result_slug(string $result): string
{
    return preg_replace('/^[a-z0-9_.-]+:/', '', $result) ?? $result;
}

function chinese_token_aliases(string $text): array
{
    $tokenMap = [
        'acacia' => ['金合欢木', '金合欢'], 'birch' => ['白桦木', '白桦'], 'cherry' => ['樱花木', '樱花'],
        'dark' => ['深色'], 'oak' => ['橡木'], 'jungle' => ['丛林木', '丛林'], 'mangrove' => ['红树木', '红树'],
        'spruce' => ['云杉木', '云杉'], 'bamboo' => ['竹', '竹子'], 'crimson' => ['绯红'], 'warped' => ['诡异'],
        'boat' => ['船'], 'chest' => ['箱子', '运输'], 'door' => ['门'], 'fence' => ['栅栏'], 'gate' => ['门'],
        'planks' => ['木板'], 'pressure' => ['压力'], 'plate' => ['板', '压力板'], 'sign' => ['告示牌'], 'slab' => ['台阶', '半砖'],
        'stairs' => ['楼梯'], 'trapdoor' => ['活板门'], 'raft' => ['筏'], 'mosaic' => ['马赛克'],
        'copper' => ['铜', '铜块'], 'cut' => ['切制'], 'exposed' => ['斑驳', '暴露'], 'oxidized' => ['氧化'],
        'weathered' => ['锈蚀', '风化'], 'waxed' => ['涂蜡'], 'stone' => ['石头', '石'], 'bricks' => ['砖块', '砖'],
        'blackstone' => ['黑石'], 'deepslate' => ['深板岩'], 'sandstone' => ['砂岩'], 'red' => ['红色', '红'],
        'smooth' => ['平滑'], 'polished' => ['磨制'], 'tuff' => ['凝灰岩'], 'quartz' => ['石英'], 'prismarine' => ['海晶石'],
        'nether' => ['下界'], 'end' => ['末地'], 'spawn' => ['刷怪'], 'egg' => ['蛋'], 'potion' => ['药水'],
        'haste' => ['急迫'], 'minecart' => ['矿车'], 'lantern' => ['灯笼'], 'torch' => ['火把'], 'iron' => ['铁'],
    ];

    $aliases = [];
    foreach (words_from_text($text) as $token) {
        if (isset($tokenMap[$token])) {
            add_aliases($aliases, $tokenMap[$token]);
        }
    }

    return $aliases;
}

function wood_recipe_names(string $slug): array
{
    $materials = [
        'dark_oak' => ['深色橡木', ['深色橡木', '深橡木', '黑橡木']],
        'acacia' => ['金合欢木', ['金合欢木', '金合欢', '相思木']],
        'bamboo' => ['竹', ['竹', '竹子']],
        'birch' => ['白桦木', ['白桦木', '白桦']],
        'cherry' => ['樱花木', ['樱花木', '樱花']],
        'crimson' => ['绯红', ['绯红', '绯红木']],
        'jungle' => ['丛林木', ['丛林木', '丛林']],
        'mangrove' => ['红树木', ['红树木', '红树']],
        'spruce' => ['云杉木', ['云杉木', '云杉']],
        'warped' => ['诡异', ['诡异', '诡异木']],
        'oak' => ['橡木', ['橡木']],
    ];

    if ($slug === 'bamboo_raft') {
        return ['竹筏', ['竹筏', '竹船']];
    }
    if ($slug === 'bamboo_chest_raft') {
        return ['运输竹筏', ['运输竹筏', '箱子竹筏', '带箱子的竹筏']];
    }
    if ($slug === 'bamboo_mosaic') {
        return ['竹马赛克', ['竹马赛克']];
    }
    if ($slug === 'bamboo_planks') {
        return ['竹板', ['竹板', '竹木板']];
    }

    $suffixes = [
        'chest_boat' => ['运输船', ['箱子船', '带箱子的船']],
        'boat' => ['船', ['小船']],
        'door' => ['门', ['木门']],
        'fence_gate' => ['栅栏门', ['栅栏门']],
        'fence' => ['栅栏', ['栅栏']],
        'planks' => ['板', ['木板']],
        'pressure_plate' => ['压力板', ['压力板']],
        'sign' => ['告示牌', ['告示牌', '牌子']],
        'slab' => ['台阶', ['半砖', '台阶']],
        'stairs' => ['楼梯', ['楼梯']],
        'trapdoor' => ['活板门', ['活板门', '地板门']],
    ];

    foreach ($materials as $key => $material) {
        if (!search_starts_with($slug, $key . '_')) {
            continue;
        }

        $rest = substr($slug, strlen($key) + 1);
        if (!isset($suffixes[$rest])) {
            continue;
        }

        list($base, $materialAliases) = $material;
        list($suffix, $suffixAliases) = $suffixes[$rest];
        $title = $base . $suffix;
        $aliases = [$title];

        foreach ($materialAliases as $materialAlias) {
            foreach (array_merge([$suffix], $suffixAliases) as $suffixAlias) {
                $aliases[] = $materialAlias . $suffixAlias;
            }
        }

        if ($rest === 'planks') {
            $aliases[] = $base . '木板';
        }

        return [$title, $aliases];
    }

    return ['', []];
}

function recipe_chinese_names(string $slug, string $source, string $basename): array
{
    $exact = [
        'andesite' => '安山岩', 'barrel' => '木桶', 'bedrock' => '基岩', 'blackstone' => '黑石',
        'bone_block' => '骨块', 'bow' => '弓', 'bowl' => '碗', 'bread' => '面包', 'bricks' => '砖块',
        'bucket' => '桶', 'budding_amethyst' => '紫水晶母岩', 'cauldron' => '炼药锅', 'chest' => '箱子',
        'chest_minecart' => '运输矿车', 'clock' => '时钟', 'cobbled_deepslate' => '深板岩圆石',
        'cobblestone' => '圆石', 'command_block' => '命令方块', 'compass' => '指南针', 'composter' => '堆肥桶',
        'cut_copper' => '切制铜块', 'exposed_cut_copper' => '斑驳的切制铜块', 'oxidized_cut_copper' => '氧化的切制铜块',
        'weathered_cut_copper' => '锈蚀的切制铜块', 'waxed_copper_block' => '涂蜡铜块',
        'waxed_cut_copper' => '涂蜡切制铜块', 'waxed_cut_copper_slab' => '涂蜡切制铜台阶',
        'waxed_cut_copper_stairs' => '涂蜡切制铜楼梯', 'waxed_exposed_copper' => '涂蜡斑驳的铜块',
        'waxed_exposed_cut_copper' => '涂蜡斑驳的切制铜块', 'waxed_exposed_cut_copper_slab' => '涂蜡斑驳的切制铜台阶',
        'waxed_exposed_cut_copper_stairs' => '涂蜡斑驳的切制铜楼梯', 'waxed_oxidized_copper' => '涂蜡氧化的铜块',
        'waxed_oxidized_cut_copper' => '涂蜡氧化的切制铜块', 'waxed_oxidized_cut_copper_slab' => '涂蜡氧化的切制铜台阶',
        'waxed_oxidized_cut_copper_stairs' => '涂蜡氧化的切制铜楼梯', 'waxed_weathered_copper' => '涂蜡锈蚀的铜块',
        'waxed_weathered_cut_copper' => '涂蜡锈蚀的切制铜块', 'waxed_weathered_cut_copper_slab' => '涂蜡锈蚀的切制铜台阶',
        'waxed_weathered_cut_copper_stairs' => '涂蜡锈蚀的切制铜楼梯', 'dark_prismarine' => '暗海晶石',
        'deepslate_bricks' => '深板岩砖', 'deepslate_tiles' => '深板岩瓦', 'diorite' => '闪长岩',
        'dispenser' => '发射器', 'end_portal_frame' => '末地传送门框架', 'end_stone_bricks' => '末地石砖',
        'furnace_minecart' => '动力矿车', 'glistering_melon_slice' => '闪烁的西瓜片', 'golden_apple' => '金苹果',
        'golden_carrot' => '金胡萝卜', 'granite' => '花岗岩', 'heavy_weighted_pressure_plate' => '重质测重压力板',
        'hopper_minecart' => '漏斗矿车', 'iron_chain' => '锁链', 'iron_door' => '铁门', 'iron_trapdoor' => '铁活板门',
        'ladder' => '梯子', 'lantern' => '灯笼', 'light_weighted_pressure_plate' => '轻质测重压力板',
        'lightning_rod' => '避雷针', 'minecart' => '矿车', 'mossy_cobblestone' => '苔石', 'mossy_stone_bricks' => '苔石砖',
        'mud_bricks' => '泥砖', 'nether_bricks' => '下界砖块', 'piglin_spawn_egg' => '猪灵刷怪蛋',
        'pillager_spawn_egg' => '掠夺者刷怪蛋', 'polished_andesite' => '磨制安山岩', 'polished_blackstone' => '磨制黑石',
        'polished_blackstone_bricks' => '磨制黑石砖', 'polished_deepslate' => '磨制深板岩', 'polished_diorite' => '磨制闪长岩',
        'polished_granite' => '磨制花岗岩', 'polished_tuff' => '磨制凝灰岩', 'potion' => '药水',
        'prismarine' => '海晶石', 'prismarine_bricks' => '海晶石砖', 'purpur_block' => '紫珀块', 'quartz_block' => '石英块',
        'red_nether_bricks' => '红色下界砖块', 'red_sandstone' => '红砂岩', 'reinforced_deepslate' => '强化深板岩',
        'repeater' => '红石中继器', 'sandstone' => '砂岩', 'smooth_quartz' => '平滑石英块',
        'smooth_red_sandstone' => '平滑红砂岩', 'smooth_sandstone' => '平滑砂岩', 'smooth_stone' => '平滑石头',
        'shulker_spawn_egg' => '潜影贝刷怪蛋', 'soul_lantern' => '灵魂灯笼', 'soul_torch' => '灵魂火把', 'stick' => '木棍', 'stone' => '石头',
        'stone_bricks' => '石砖', 'tnt_minecart' => 'TNT矿车', 'tuff' => '凝灰岩', 'tuff_bricks' => '凝灰岩砖',
        'villager_spawn_egg' => '村民刷怪蛋', 'cut_red_sandstone' => '切制红砂岩', 'cut_sandstone' => '切制砂岩',
    ];

    list($woodTitle, $woodAliases) = wood_recipe_names($slug);
    $title = $woodTitle !== '' ? $woodTitle : ($exact[$slug] ?? '');
    $aliases = $woodAliases;
    if ($title !== '') {
        $aliases[] = $title;
    }

    if ($slug === 'potion' && search_contains($source . ' ' . $basename, 'haste_potion')) {
        $title = '急迫药水';
        add_aliases($aliases, ['急迫药水', '急迫', '药水']);
    }

    $parts = $source . ' ' . $basename . ' ' . $slug;
    add_aliases($aliases, chinese_token_aliases($parts));

    $initialAliases = [];
    foreach ($aliases as $alias) {
        $initials = zh_initials($alias);
        if ($initials !== '') {
            $initialAliases[] = $initials;
        }
    }
    add_aliases($aliases, $initialAliases);

    return [
        'title' => $title,
        'aliases' => array_values(array_unique($aliases)),
    ];
}

function load_recipe_materials(): array
{
    static $materials = null;
    if ($materials !== null) {
        return $materials;
    }

    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'materials.json';
    if (!is_file($path)) {
        return $materials = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return $materials = is_array($decoded) ? $decoded : [];
}

function load_minecraft_language(): array
{
    static $translations = null;
    if ($translations !== null) {
        return $translations;
    }

    $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '统计数据' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'zh_cn.json';
    if (!is_file($path)) {
        return $translations = [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    return $translations = is_array($decoded) ? $decoded : [];
}

function material_display_name(string $id, array $translations): string
{
    if (strpos($id, ' / ') !== false) {
        return implode(' / ', array_map(static function (string $option) use ($translations): string {
            return material_display_name($option, $translations);
        }, explode(' / ', $id)));
    }

    $tagNames = [
        '#minecraft:logs' => '任意原木',
        '#minecraft:planks' => '任意木板',
        'minecraft:tnt' => 'TNT 炸药',
    ];
    if (isset($tagNames[$id])) {
        return $tagNames[$id];
    }

    $clean = ltrim($id, '#');
    $parts = explode(':', $clean, 2);
    $namespace = count($parts) === 2 ? $parts[0] : 'minecraft';
    $slug = count($parts) === 2 ? $parts[1] : $parts[0];
    foreach (['item', 'block'] as $type) {
        $key = $type . '.' . $namespace . '.' . $slug;
        if (isset($translations[$key]) && trim((string) $translations[$key]) !== '') {
            return (string) $translations[$key];
        }
    }

    return str_replace('_', ' ', $slug);
}

function localize_recipe_materials(array $materials, array $translations): array
{
    foreach ($materials as &$material) {
        $material['name'] = material_display_name((string) ($material['id'] ?? ''), $translations);
    }
    unset($material);
    return $materials;
}

function recipe_public_thumbnail_url(int $id, ?string $thumbnail): string
{
    $thumbnail = trim((string) $thumbnail);
    if ($id <= 0 || $thumbnail === '') {
        return '';
    }
    return '/配方/thumbnail.php?id=' . $id . '&v=' . rawurlencode($thumbnail);
}

function normalize_recipe_item_id(string $id): string
{
    $id = trim($id);
    if ($id === '') {
        return '';
    }
    if (str_starts_with($id, '#')) {
        return '#' . normalize_recipe_item_id(substr($id, 1));
    }
    return str_contains($id, ':') ? $id : 'minecraft:' . $id;
}

function recipe_item_label(string $id, array $translations): string
{
    $id = normalize_recipe_item_id($id);
    if ($id === '') {
        return '';
    }
    return material_display_name($id, $translations);
}

function recipe_item_catalog(): array
{
    $translations = load_minecraft_language();
    $items = [];

    $add = static function (string $id) use (&$items, $translations): void {
        $id = normalize_recipe_item_id($id);
        if ($id === '' || str_starts_with($id, '#')) {
            return;
        }
        $items[$id] = [
            'id' => $id,
            'name' => recipe_item_label($id, $translations),
        ];
    };

    foreach (load_recipe_materials() as $materials) {
        foreach ((array) $materials as $material) {
            $rawId = (string) ($material['id'] ?? '');
            if (strpos($rawId, ' / ') !== false) {
                foreach (explode(' / ', $rawId) as $option) {
                    $add($option);
                }
            } else {
                $add($rawId);
            }
        }
    }

    $path = recipe_csv_path();
    $handle = @fopen($path, 'rb');
    if ($handle !== false) {
        $headers = read_recipe_csv_row($handle);
        $headers = $headers === false ? [] : normalize_csv_headers($headers);
        if (has_required_csv_headers($headers)) {
            while (($row = read_recipe_csv_row($handle)) !== false) {
                if (count($row) < count($headers)) {
                    $row = array_pad($row, count($headers), '');
                }
                $record = array_combine($headers, array_slice($row, 0, count($headers)));
                if ($record !== false) {
                    $add((string) ($record['Result'] ?? ''));
                }
            }
        }
        fclose($handle);
    }

    foreach (recipe_common_item_ids() as $id) {
        $add($id);
    }

    try {
        foreach (recipe_db()->query('SELECT output_item_id, input FROM recipes')->fetchAll() as $record) {
            $add((string) $record['output_item_id']);
            $decoded = json_decode((string) $record['input'], true);
            recipe_collect_input_items($decoded, $add);
        }
    } catch (Throwable $exception) {
        error_log('Recipe item catalog database read failed: ' . $exception->getMessage());
    }

    uasort($items, static function (array $a, array $b): int {
        return strnatcasecmp((string) $a['name'], (string) $b['name']) ?: strnatcasecmp((string) $a['id'], (string) $b['id']);
    });
    return array_values($items);
}

function recipe_collect_input_items($input, callable $add): void
{
    if (!is_array($input)) {
        return;
    }
    foreach ($input as $value) {
        if (is_array($value) && isset($value['itemId'])) {
            $add((string) $value['itemId']);
        } elseif (is_array($value)) {
            recipe_collect_input_items($value, $add);
        }
    }
}

function recipe_common_item_ids(): array
{
    return [
        'minecraft:oak_log', 'minecraft:oak_planks', 'minecraft:spruce_log', 'minecraft:spruce_planks',
        'minecraft:birch_log', 'minecraft:birch_planks', 'minecraft:jungle_log', 'minecraft:jungle_planks',
        'minecraft:acacia_log', 'minecraft:acacia_planks', 'minecraft:dark_oak_log', 'minecraft:dark_oak_planks',
        'minecraft:mangrove_log', 'minecraft:mangrove_planks', 'minecraft:cherry_log', 'minecraft:cherry_planks',
        'minecraft:bamboo', 'minecraft:bamboo_block', 'minecraft:bamboo_planks', 'minecraft:crimson_stem',
        'minecraft:crimson_planks', 'minecraft:warped_stem', 'minecraft:warped_planks', 'minecraft:stick',
        'minecraft:crafting_table', 'minecraft:chest', 'minecraft:furnace', 'minecraft:stone', 'minecraft:cobblestone',
        'minecraft:deepslate', 'minecraft:cobbled_deepslate', 'minecraft:andesite', 'minecraft:diorite',
        'minecraft:granite', 'minecraft:sandstone', 'minecraft:red_sandstone', 'minecraft:tuff', 'minecraft:bricks',
        'minecraft:iron_ingot', 'minecraft:iron_block', 'minecraft:gold_ingot', 'minecraft:gold_block',
        'minecraft:copper_ingot', 'minecraft:copper_block', 'minecraft:diamond', 'minecraft:diamond_block',
        'minecraft:emerald', 'minecraft:emerald_block', 'minecraft:netherite_ingot', 'minecraft:redstone',
        'minecraft:redstone_block', 'minecraft:coal', 'minecraft:charcoal', 'minecraft:glass', 'minecraft:sand',
        'minecraft:gravel', 'minecraft:string', 'minecraft:bow', 'minecraft:arrow', 'minecraft:bucket',
        'minecraft:water_bucket', 'minecraft:lava_bucket', 'minecraft:hopper', 'minecraft:dropper',
        'minecraft:dispenser', 'minecraft:piston', 'minecraft:sticky_piston', 'minecraft:repeater',
        'minecraft:comparator', 'minecraft:torch', 'minecraft:soul_torch', 'minecraft:lantern',
        'minecraft:paper', 'minecraft:book', 'minecraft:bookshelf', 'minecraft:leather', 'minecraft:slime_ball',
        'minecraft:bone', 'minecraft:bone_block', 'minecraft:wheat', 'minecraft:bread', 'minecraft:apple',
        'minecraft:golden_apple', 'minecraft:carrot', 'minecraft:golden_carrot', 'minecraft:nether_wart',
        'minecraft:blaze_powder', 'minecraft:magma_cream', 'minecraft:potion', 'minecraft:minecart',
        'minecraft:tnt', 'minecraft:command_block',
    ];
}

function load_database_recipes(): array
{
    $translations = load_minecraft_language();
    try {
        $stmt = recipe_db()->query(
            'SELECT id, name, type, input, output_item_id, output_count, thumbnail, created_at, updated_at
             FROM recipes ORDER BY updated_at DESC, id DESC'
        );
    } catch (Throwable $exception) {
        error_log('Recipe database read failed: ' . $exception->getMessage());
        return [];
    }

    $recipes = [];
    foreach ($stmt->fetchAll() as $record) {
        $id = (int) $record['id'];
        $outputId = normalize_recipe_item_id((string) $record['output_item_id']);
        $slug = recipe_result_slug($outputId);
        $titleEn = humanize_recipe_id($outputId);
        $title = trim((string) $record['name']);
        if ($title === '') {
            $title = recipe_item_label($outputId, $translations) ?: $titleEn;
        }
        $input = json_decode((string) $record['input'], true);
        $materials = recipe_database_materials($input, $translations);
        $type = (string) $record['type'] === 'shapeless' ? 'shapeless' : 'shaped';
        $typeLabel = $type === 'shapeless' ? '无序配方' : '有序配方';
        $searchText = normalize_search_text(implode(' ', [
            $title,
            $titleEn,
            $outputId,
            $slug,
            $type,
            $typeLabel,
            recipe_item_label($outputId, $translations),
            implode(' ', array_map(static fn (array $material): string => (string) ($material['name'] ?? '') . ' ' . (string) ($material['id'] ?? ''), $materials)),
        ]));

        $words = array_values(array_unique(words_from_text($searchText)));
        $recipes[] = [
            'number' => 1000000 + $id,
            'id' => $id,
            'source_type' => 'database',
            'file' => recipe_public_thumbnail_url($id, (string) ($record['thumbnail'] ?? '')),
            'thumbnail' => recipe_public_thumbnail_url($id, (string) ($record['thumbnail'] ?? '')),
            'source' => 'database:recipes/' . $id,
            'result' => $outputId,
            'result_slug' => $slug,
            'title' => $title,
            'title_zh' => $title,
            'title_en' => $titleEn,
            'count' => max(1, (int) $record['output_count']),
            'pack' => 'custom',
            'group' => $type,
            'recipe_type' => $type,
            'recipe_type_label' => $typeLabel,
            'aliases' => [$typeLabel, recipe_item_label($outputId, $translations)],
            'words' => $words,
            'search_text' => $searchText,
            'search_compact' => str_replace(' ', '', $searchText),
            'initials' => initials_from_words($words),
            'materials' => $materials,
            'updated_at' => (string) ($record['updated_at'] ?? ''),
        ];
    }

    return $recipes;
}

function load_synced_recipes(): array
{
    $path = recipe_project_root_path() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'inbox' . DIRECTORY_SEPARATOR . 'recipes.json.php';
    if (!is_file($path)) {
        return [];
    }
    $raw = (string) @file_get_contents($path);
    $prefix = "<?php http_response_code(404); exit; ?>\n";
    if (strncmp($raw, $prefix, strlen($prefix)) === 0) {
        $raw = substr($raw, strlen($prefix));
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $items = $decoded['items'] ?? $decoded['recipes'] ?? $decoded['data'] ?? $decoded;
    if (!is_array($items)) {
        return [];
    }

    $translations = load_minecraft_language();
    $recipes = [];
    $offset = 800000;
    foreach ($items as $index => $record) {
        if (!is_array($record)) {
            continue;
        }
        $outputValue = $record['result'] ?? $record['output_item_id'] ?? $record['output'] ?? '';
        if (is_array($outputValue)) {
            $outputValue = $outputValue['itemId'] ?? $outputValue['id'] ?? '';
        }
        $result = normalize_recipe_item_id((string) $outputValue);
        if ($result === '' || str_starts_with($result, '#')) {
            continue;
        }
        $slug = recipe_result_slug($result);
        $titleEn = humanize_recipe_id($result);
        $title = trim((string) ($record['title'] ?? $record['name'] ?? ''));
        if ($title === '') {
            $title = recipe_item_label($result, $translations) ?: $titleEn;
        }
        $materials = [];
        $collectMaterial = static function ($material) use (&$materials, &$collectMaterial, $translations): void {
            if (!is_array($material)) {
                return;
            }
            $id = normalize_recipe_item_id((string) ($material['id'] ?? $material['itemId'] ?? ''));
            if ($id !== '') {
                $materials[] = [
                    'id' => $id,
                    'name' => recipe_item_label($id, $translations),
                    'count' => max(1, (int) ($material['count'] ?? 1)),
                ];
                return;
            }
            foreach ($material as $child) {
                $collectMaterial($child);
            }
        };
        $collectMaterial((array) ($record['materials'] ?? $record['input'] ?? []));
        $file = trim((string) ($record['file'] ?? $record['thumbnail'] ?? ''));
        if ($file !== '' && !preg_match('#^(?:https?://|/)#', $file)) {
            $file = 'images/' . basename($file);
        }
        $type = in_array(($record['type'] ?? ''), ['shaped', 'shapeless'], true) ? (string) $record['type'] : '';
        $searchText = normalize_search_text(implode(' ', [
            $title,
            $titleEn,
            $result,
            $slug,
            $type,
            implode(' ', array_map(static fn (array $material): string => (string) $material['id'] . ' ' . (string) $material['name'], $materials)),
        ]));
        $words = array_values(array_unique(words_from_text($searchText)));
        $recipes[] = [
            'number' => $offset + (int) $index,
            'source_type' => 'sync',
            'file' => $file,
            'thumbnail' => $file,
            'source' => (string) ($record['source'] ?? 'sync:recipes'),
            'result' => $result,
            'result_slug' => $slug,
            'title' => $title,
            'title_zh' => $title,
            'title_en' => $titleEn,
            'count' => max(1, (int) ($record['count'] ?? $record['output_count'] ?? 1)),
            'pack' => (string) ($record['pack'] ?? 'sync'),
            'group' => (string) ($record['group'] ?? $type),
            'recipe_type' => $type,
            'recipe_type_label' => $type === 'shapeless' ? '无序配方' : ($type === 'shaped' ? '有序配方' : ''),
            'aliases' => [],
            'words' => $words,
            'search_text' => $searchText,
            'search_compact' => str_replace(' ', '', $searchText),
            'initials' => initials_from_words($words),
            'materials' => $materials,
        ];
    }
    return $recipes;
}

function recipe_database_materials($input, array $translations): array
{
    $counts = [];
    $collect = static function ($value) use (&$counts, &$collect): void {
        if (!is_array($value)) {
            return;
        }
        if (isset($value['itemId'])) {
            $id = normalize_recipe_item_id((string) $value['itemId']);
            if ($id !== '') {
                $counts[$id] = ($counts[$id] ?? 0) + max(1, (int) ($value['count'] ?? 1));
            }
            return;
        }
        foreach ($value as $child) {
            $collect($child);
        }
    };
    $collect($input);

    $materials = [];
    foreach ($counts as $id => $count) {
        $materials[] = [
            'id' => $id,
            'name' => recipe_item_label($id, $translations),
            'count' => $count,
        ];
    }
    return $materials;
}
function load_recipes(): array
{
    static $recipes = null;
    if ($recipes !== null) {
        return $recipes;
    }

    $path = recipe_csv_path();
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return [];
    }

    $headers = read_recipe_csv_row($handle);
    if ($headers === false) {
        fclose($handle);
        return [];
    }

    $headers = normalize_csv_headers($headers);
    if (!has_required_csv_headers($headers)) {
        fclose($handle);
        return [];
    }

    $recipes = [];
    $materialsByNumber = load_recipe_materials();
    $translations = load_minecraft_language();

    while (($row = read_recipe_csv_row($handle)) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }

        if (count($row) < count($headers)) {
            $row = array_pad($row, count($headers), '');
        } elseif (count($row) > count($headers)) {
            $row = array_slice($row, 0, count($headers));
        }

        $record = array_combine($headers, $row);
        if ($record === false) {
            continue;
        }

        $file = 'images/' . basename((string) $record['File']);
        $source = (string) $record['Source'];
        $result = (string) $record['Result'];
        $titleEn = humanize_recipe_id($result);
        $slug = recipe_result_slug($result);
        $pack = recipe_pack_from_source($source);
        $group = recipe_group_from_source($source);
        $basename = preg_replace('/^\d+-/', '', pathinfo($file, PATHINFO_FILENAME)) ?? pathinfo($file, PATHINFO_FILENAME);
        $zh = recipe_chinese_names($slug, $source, $basename);
        $titleZh = $zh['title'] !== '' ? $zh['title'] : $titleEn;
        $aliases = $zh['aliases'];

        $words = array_values(array_unique(array_merge(
            words_from_text($titleZh),
            words_from_text($titleEn),
            words_from_text($slug),
            words_from_text($basename),
            words_from_text($source),
            words_from_text(implode(' ', $aliases))
        )));

        $searchText = normalize_search_text(implode(' ', array_merge([
            $titleZh,
            $titleEn,
            $result,
            $slug,
            $basename,
            $source,
            $pack,
            $group,
        ], $aliases)));

        $recipes[] = [
            'number' => (int) $record['Number'],
            'file' => $file,
            'source' => $source,
            'result' => $result,
            'result_slug' => $slug,
            'title' => $titleZh,
            'title_zh' => $titleZh,
            'title_en' => $titleEn,
            'count' => (int) $record['Count'],
            'pack' => $pack,
            'group' => $group,
            'aliases' => $aliases,
            'words' => $words,
            'search_text' => $searchText,
            'search_compact' => str_replace(' ', '', $searchText),
            'initials' => initials_from_words($words),
            'materials' => localize_recipe_materials($materialsByNumber[(string) $record['Number']] ?? [], $translations),
        ];
    }

    fclose($handle);
    $recipes = array_merge($recipes, load_synced_recipes(), load_database_recipes());
    return $recipes;
}

function is_subsequence(string $needle, string $haystack): bool
{
    if ($needle === '') {
        return true;
    }

    $offset = 0;
    $needleLength = strlen($needle);
    $haystackLength = strlen($haystack);

    for ($i = 0; $i < $needleLength; $i++) {
        $found = false;
        for (; $offset < $haystackLength; $offset++) {
            if ($needle[$i] === $haystack[$offset]) {
                $found = true;
                $offset++;
                break;
            }
        }

        if (!$found) {
            return false;
        }
    }

    return true;
}

function all_terms_contained(array $terms, string $haystack): bool
{
    foreach ($terms as $term) {
        if (!search_contains($haystack, $term)) {
            return false;
        }
    }

    return true;
}

function has_cjk(string $value): bool
{
    return preg_match('/[\x{3400}-\x{9fff}]/u', $value) === 1;
}

function fuzzy_term_score(array $terms, array $words): int
{
    if ($terms === [] || $words === []) {
        return 0;
    }

    $totalDistance = 0;
    foreach ($terms as $term) {
        if (has_cjk($term)) {
            return 0;
        }

        $best = PHP_INT_MAX;
        foreach ($words as $word) {
            if (has_cjk($word)) {
                continue;
            }

            $distance = levenshtein($term, $word);
            if ($distance < $best) {
                $best = $distance;
            }
        }

        $limit = strlen($term) <= 4 ? 1 : (strlen($term) <= 7 ? 2 : 3);
        if ($best > $limit) {
            return 0;
        }

        $totalDistance += $best;
    }

    return max(1, 420 - ($totalDistance * 35));
}

function score_recipe(array $recipe, string $query): array
{
    $normalized = normalize_search_text($query);
    $compact = str_replace(' ', '', $normalized);
    $terms = words_from_text($query);

    if ($normalized === '') {
        return [1, 'all'];
    }

    $score = 0;
    $match = '';
    $title = normalize_search_text((string) $recipe['title']);
    $titleEn = normalize_search_text((string) $recipe['title_en']);
    $slug = normalize_search_text((string) $recipe['result_slug']);
    $result = normalize_search_text((string) $recipe['result']);
    $haystack = (string) $recipe['search_text'];
    $haystackCompact = (string) $recipe['search_compact'];
    $initials = (string) $recipe['initials'];

    if ($normalized === $slug || $normalized === $result || $normalized === $title || $normalized === $titleEn) {
        $score = 1000;
        $match = 'exact';
    } elseif (search_starts_with($slug, $normalized) || search_starts_with($title, $normalized) || search_starts_with($titleEn, $normalized)) {
        $score = 900;
        $match = 'prefix';
    } elseif (search_contains($haystack, $normalized)) {
        $score = 790;
        $match = 'contains';
    } elseif ($terms !== [] && all_terms_contained($terms, $haystack)) {
        $score = 730;
        $match = 'terms';
    } elseif ($compact !== '' && search_starts_with($initials, $compact)) {
        $score = 650;
        $match = 'initials';
    } elseif ($compact !== '' && search_contains($initials, $compact)) {
        $score = 600;
        $match = 'initials';
    } elseif ($compact !== '' && is_subsequence($compact, $haystackCompact)) {
        $score = 500;
        $match = 'fuzzy';
    } else {
        $score = fuzzy_term_score($terms, $recipe['words']);
        $match = $score > 0 ? 'fuzzy' : '';
    }

    if ($score > 0) {
        $score -= min(50, (int) floor(strlen($haystack) / 40));
    }

    return [$score, $match];
}

function search_recipes(string $query, int $limit = 0): array
{
    $results = [];
    foreach (load_recipes() as $recipe) {
        list($score, $match) = score_recipe($recipe, $query);
        if ($score <= 0) {
            continue;
        }

        $recipe['score'] = $score;
        $recipe['match'] = $match;
        unset($recipe['words'], $recipe['search_text'], $recipe['search_compact'], $recipe['initials']);
        $results[] = $recipe;
    }

    usort($results, static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $a['number'] <=> $b['number'];
        }

        return $b['score'] <=> $a['score'];
    });

    if ($limit > 0) {
        return array_slice($results, 0, $limit);
    }

    return $results;
}

function recipes_to_public_payload(array $recipes): array
{
    return array_map(static function (array $recipe): array {
        return [
            'number' => $recipe['number'],
            'id' => $recipe['id'] ?? null,
            'source_type' => $recipe['source_type'] ?? 'static',
            'file' => $recipe['file'],
            'thumbnail' => $recipe['thumbnail'] ?? $recipe['file'],
            'source' => $recipe['source'],
            'result' => $recipe['result'],
            'result_slug' => $recipe['result_slug'],
            'title' => $recipe['title'],
            'title_zh' => $recipe['title_zh'],
            'title_en' => $recipe['title_en'],
            'count' => $recipe['count'],
            'pack' => $recipe['pack'],
            'group' => $recipe['group'],
            'recipe_type' => $recipe['recipe_type'] ?? '',
            'recipe_type_label' => $recipe['recipe_type_label'] ?? '',
            'score' => $recipe['score'] ?? 0,
            'match' => $recipe['match'] ?? 'all',
            'materials' => $recipe['materials'] ?? [],
        ];
    }, $recipes);
}
