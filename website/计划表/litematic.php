<?php
declare(strict_types=1);

const PLAN_LITEMATIC_MAX_DECOMPRESSED_BYTES = 128 * 1024 * 1024;
const PLAN_LITEMATIC_MAX_BLOCKS = 50000000;
const PLAN_LITEMATIC_MAX_PALETTE_SIZE = 65536;
const PLAN_NBT_MAX_DEPTH = 64;
const PLAN_NBT_MAX_COLLECTION_ITEMS = 10000000;

final class PlanNbtLongArray
{
    public function __construct(
        private string $data,
        private int $offset,
        private int $count,
    ) {
    }

    public function count(): int
    {
        return $this->count;
    }

    public function get(int $index): int
    {
        if ($index < 0 || $index >= $this->count) {
            throw new RuntimeException('投影方块状态数据不完整');
        }

        return unpack('Jvalue', substr($this->data, $this->offset + ($index * 8), 8))['value'];
    }
}

final class PlanNbtReader
{
    private int $offset = 0;
    private int $length;

    public function __construct(private string $data)
    {
        $this->length = strlen($data);
    }

    private function take(int $bytes): string
    {
        if ($bytes < 0 || $bytes > $this->length - $this->offset) {
            throw new RuntimeException('NBT 文件不完整');
        }

        $value = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;
        return $value;
    }

    private function byte(): int
    {
        return unpack('cvalue', $this->take(1))['value'];
    }

    private function ubyte(): int
    {
        return ord($this->take(1));
    }

    private function ushort(): int
    {
        return unpack('nvalue', $this->take(2))['value'];
    }

    private function short(): int
    {
        $value = $this->ushort();
        return $value > 32767 ? $value - 65536 : $value;
    }

    private function int(): int
    {
        $value = unpack('Nvalue', $this->take(4))['value'];
        return $value > 2147483647 ? $value - 4294967296 : $value;
    }

    private function long(): int
    {
        return unpack('Jvalue', $this->take(8))['value'];
    }

    private function string(): string
    {
        return $this->take($this->ushort());
    }

    private function collectionLength(int $itemBytes, string $label): int
    {
        $count = $this->int();
        if ($count < 0 || $count > PLAN_NBT_MAX_COLLECTION_ITEMS) {
            throw new RuntimeException($label . '过大');
        }
        if ($itemBytes > 0 && $count > intdiv($this->length - $this->offset, $itemBytes)) {
            throw new RuntimeException('NBT 文件不完整');
        }
        return $count;
    }

    private function tag(int $type, int $depth): mixed
    {
        if ($depth > PLAN_NBT_MAX_DEPTH) {
            throw new RuntimeException('NBT 嵌套层级过深');
        }

        return match ($type) {
            1 => $this->byte(),
            2 => $this->short(),
            3 => $this->int(),
            4 => $this->long(),
            5 => unpack('Gvalue', $this->take(4))['value'],
            6 => unpack('Evalue', $this->take(8))['value'],
            7 => $this->byteArray(),
            8 => $this->string(),
            9 => $this->list($depth + 1),
            10 => $this->compound($depth + 1),
            11 => $this->intArray(),
            12 => $this->longArray(),
            default => throw new RuntimeException('未知 NBT 标签类型'),
        };
    }

    private function byteArray(): string
    {
        return $this->take($this->collectionLength(1, 'NBT ByteArray'));
    }

    private function intArray(): string
    {
        return $this->take($this->collectionLength(4, 'NBT IntArray') * 4);
    }

    private function longArray(): PlanNbtLongArray
    {
        $count = $this->collectionLength(8, 'NBT LongArray');
        $array = new PlanNbtLongArray($this->data, $this->offset, $count);
        $this->offset += $count * 8;
        return $array;
    }

    private function list(int $depth): array
    {
        $type = $this->ubyte();
        $count = $this->collectionLength(0, 'NBT List');
        if ($type === 0 && $count !== 0) {
            throw new RuntimeException('NBT List 类型无效');
        }

        $output = [];
        for ($index = 0; $index < $count; $index++) {
            $output[] = $this->tag($type, $depth);
        }
        return $output;
    }

    private function compound(int $depth): array
    {
        if ($depth > PLAN_NBT_MAX_DEPTH) {
            throw new RuntimeException('NBT 嵌套层级过深');
        }

        $output = [];
        $tagCount = 0;
        while (true) {
            $type = $this->ubyte();
            if ($type === 0) {
                return $output;
            }
            if (++$tagCount > PLAN_NBT_MAX_COLLECTION_ITEMS) {
                throw new RuntimeException('NBT Compound 标签过多');
            }
            $output[$this->string()] = $this->tag($type, $depth);
        }
    }

    public function root(): array
    {
        if ($this->ubyte() !== 10) {
            throw new RuntimeException('NBT 根标签不是 Compound');
        }
        $this->string();
        return $this->compound(0);
    }
}

function plan_read_litematic_gzip(string $path): string
{
    $handle = @gzopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('无法读取上传文件或文件不是有效的 gzip Litematica 投影');
    }

    $raw = '';
    try {
        while (!gzeof($handle)) {
            $remaining = PLAN_LITEMATIC_MAX_DECOMPRESSED_BYTES + 1 - strlen($raw);
            if ($remaining <= 0) {
                throw new RuntimeException('投影解压后超过 128MB 限制');
            }
            $chunk = gzread($handle, min(1024 * 1024, $remaining));
            if ($chunk === false || ($chunk === '' && !gzeof($handle))) {
                throw new RuntimeException('投影 gzip 数据损坏');
            }
            $raw .= $chunk;
        }
    } finally {
        gzclose($handle);
    }

    if (strlen($raw) > PLAN_LITEMATIC_MAX_DECOMPRESSED_BYTES) {
        throw new RuntimeException('投影解压后超过 128MB 限制');
    }
    return $raw;
}

function plan_decode_litematic_region(
    PlanNbtLongArray $states,
    array $palette,
    int $totalBlocks,
    int $bitsPerEntry,
): ?array {
    $mask = (1 << $bitsPerEntry) - 1;
    $counts = [];

    for ($index = 0; $index < $totalBlocks; $index++) {
        $bitIndex = $index * $bitsPerEntry;
        $wordIndex = intdiv($bitIndex, 64);
        $shift = $bitIndex % 64;
        if ($shift + $bitsPerEntry <= 64) {
            $paletteIndex = ($states->get($wordIndex) >> $shift) & $mask;
        } else {
            $lowBits = 64 - $shift;
            $lowMask = (1 << $lowBits) - 1;
            $highMask = (1 << ($bitsPerEntry - $lowBits)) - 1;
            $paletteIndex = (($states->get($wordIndex) >> $shift) & $lowMask)
                | (($states->get($wordIndex + 1) & $highMask) << $lowBits);
        }

        $state = $palette[$paletteIndex] ?? null;
        $blockId = is_array($state) ? ($state['Name'] ?? null) : null;
        if (!is_string($blockId) || $blockId === '') {
            return null;
        }
        if ($blockId !== 'minecraft:air') {
            $counts[$blockId] = ($counts[$blockId] ?? 0) + 1;
        }
    }

    return $counts;
}

function plan_parse_litematic(string $path): array
{
    $root = (new PlanNbtReader(plan_read_litematic_gzip($path)))->root();
    $regions = $root['Regions'] ?? null;
    if (!is_array($regions) || $regions === []) {
        throw new RuntimeException('投影中没有 Regions 数据');
    }

    $counts = [];
    $decodedBlocks = 0;
    foreach ($regions as $region) {
        if (!is_array($region)) {
            throw new RuntimeException('投影区域格式无效');
        }

        $palette = $region['BlockStatePalette'] ?? null;
        $states = $region['BlockStates'] ?? null;
        $size = $region['Size'] ?? null;
        if (!is_array($palette) || !$states instanceof PlanNbtLongArray || !is_array($size) || $palette === []) {
            throw new RuntimeException('投影区域缺少方块状态数据');
        }

        $paletteSize = count($palette);
        if ($paletteSize > PLAN_LITEMATIC_MAX_PALETTE_SIZE) {
            throw new RuntimeException('投影方块调色板过大');
        }

        $sizeX = abs((int) ($size['x'] ?? 0));
        $sizeY = abs((int) ($size['y'] ?? 0));
        $sizeZ = abs((int) ($size['z'] ?? 0));
        if ($sizeX === 0 || $sizeY === 0 || $sizeZ === 0) {
            continue;
        }
        if ($sizeX > PLAN_LITEMATIC_MAX_BLOCKS
            || $sizeY > intdiv(PLAN_LITEMATIC_MAX_BLOCKS, $sizeX)
            || $sizeZ > intdiv(PLAN_LITEMATIC_MAX_BLOCKS, $sizeX * $sizeY)) {
            throw new RuntimeException('投影总方块数超过 5000 万限制');
        }

        $totalBlocks = $sizeX * $sizeY * $sizeZ;
        if ($decodedBlocks > PLAN_LITEMATIC_MAX_BLOCKS - $totalBlocks) {
            throw new RuntimeException('投影总方块数超过 5000 万限制');
        }
        $decodedBlocks += $totalBlocks;

        $bitsPerEntry = max(2, strlen(decbin($paletteSize - 1)));
        $requiredLongs = intdiv(($totalBlocks * $bitsPerEntry) + 63, 64);
        if ($states->count() < $requiredLongs) {
            throw new RuntimeException('投影方块状态数据不完整');
        }

        $regionCounts = plan_decode_litematic_region(
            $states,
            $palette,
            $totalBlocks,
            $bitsPerEntry,
        );
        if ($regionCounts === null) {
            throw new RuntimeException('无法读取投影中的方块数据，请确认文件完整后重试');
        }
        foreach ($regionCounts as $blockId => $count) {
            $counts[$blockId] = ($counts[$blockId] ?? 0) + $count;
        }
    }

    foreach (['water' => 'water_bucket', 'lava' => 'lava_bucket'] as $source => $target) {
        $sourceId = 'minecraft:' . $source;
        if (isset($counts[$sourceId])) {
            $targetId = 'minecraft:' . $target;
            $counts[$targetId] = ($counts[$targetId] ?? 0) + $counts[$sourceId];
            unset($counts[$sourceId]);
        }
    }

    arsort($counts, SORT_NUMERIC);
    $materials = [];
    foreach ($counts as $blockId => $count) {
        $stackSize = plan_material_stack_size($blockId);
        $materials[] = [
            'blockId' => $blockId,
            'displayName' => plan_block_name($blockId),
            'count' => $count,
            'boxes' => (int) ceil($count / (27 * $stackSize)),
            'stacks' => (int) ceil($count / $stackSize),
        ];
    }
    return $materials;
}

function plan_material_stack_size(string $id): int
{
    $itemId = str_contains($id, ':') ? substr($id, strpos($id, ':') + 1) : $id;
    if ($itemId === 'cake'
        || $itemId === 'water_bucket'
        || $itemId === 'lava_bucket'
        || str_ends_with($itemId, '_bed')
        || $itemId === 'shulker_box'
        || str_ends_with($itemId, '_shulker_box')) {
        return 1;
    }
    if (str_ends_with($itemId, '_sign')
        || str_ends_with($itemId, '_hanging_sign')
        || str_ends_with($itemId, '_banner')) {
        return 16;
    }
    return 64;
}

function plan_block_name(string $id): string
{
    static $translations = null;
    if ($translations === null) {
        $file = __DIR__ . '/translations.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $translations = [
            'blocks' => is_array($data['blocks'] ?? null) ? $data['blocks'] : [],
            'items' => is_array($data['items'] ?? null) ? $data['items'] : [],
        ];
    }

    $normalized = str_starts_with($id, 'minecraft:') ? $id : 'minecraft:' . $id;
    $fallback = str_replace('minecraft:', '', $normalized);
    return (string) ($translations['blocks'][$normalized]
        ?? $translations['items'][$normalized]
        ?? str_replace('_', ' ', $fallback));
}
