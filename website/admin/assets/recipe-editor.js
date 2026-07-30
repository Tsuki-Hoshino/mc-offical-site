(function () {
    const data = window.RecipeEditorData || {};
    const root = document.querySelector('[data-recipe-editor]');
    if (!root) return;

    const COMMON_ICON_NAMES = {
        oak_log: 'oak-log', oak_planks: 'oak-planks', spruce_log: 'spruce-log', spruce_planks: 'spruce-planks',
        birch_log: 'birch-log', birch_planks: 'birch-planks', jungle_log: 'jungle-log', jungle_planks: 'jungle-planks',
        acacia_log: 'acacia-log', acacia_planks: 'acacia-planks', dark_oak_log: 'dark-oak-log', dark_oak_planks: 'dark-oak-planks',
        mangrove_log: 'mangrove-log', mangrove_planks: 'mangrove-planks', cherry_log: 'cherry-log', cherry_planks: 'cherry-planks',
        bamboo: 'bamboo', bamboo_block: 'block-of-bamboo', bamboo_planks: 'bamboo-planks', crimson_stem: 'crimson-stem',
        crimson_planks: 'crimson-planks', warped_stem: 'warped-stem', warped_planks: 'warped-planks', stick: 'stick',
        crafting_table: 'crafting-table', chest: 'chest', furnace: 'furnace', stone: 'stone', cobblestone: 'cobblestone',
        deepslate: 'deepslate', cobbled_deepslate: 'cobbled-deepslate', andesite: 'andesite', diorite: 'diorite',
        granite: 'granite', sandstone: 'sandstone', red_sandstone: 'red-sandstone', tuff: 'tuff', bricks: 'bricks',
        iron_ingot: 'iron-ingot', iron_block: 'block-of-iron', gold_ingot: 'gold-ingot', gold_block: 'block-of-gold',
        copper_ingot: 'copper-ingot', copper_block: 'block-of-copper', diamond: 'diamond', diamond_block: 'block-of-diamond',
        emerald: 'emerald', emerald_block: 'block-of-emerald', netherite_ingot: 'netherite-ingot', redstone: 'redstone-dust',
        redstone_block: 'block-of-redstone', coal: 'coal', charcoal: 'charcoal', glass: 'glass', sand: 'sand',
        gravel: 'gravel', string: 'string', bow: 'bow', arrow: 'arrow', bucket: 'bucket',
        water_bucket: 'water-bucket', lava_bucket: 'lava-bucket', hopper: 'hopper', dropper: 'dropper',
        dispenser: 'dispenser', piston: 'piston', sticky_piston: 'sticky-piston', repeater: 'redstone-repeater',
        comparator: 'redstone-comparator', torch: 'torch', soul_torch: 'soul-torch', lantern: 'lantern',
        paper: 'paper', book: 'book', bookshelf: 'bookshelf', leather: 'leather', slime_ball: 'slimeball',
        bone: 'bone', bone_block: 'bone-block', wheat: 'wheat', bread: 'bread', apple: 'apple',
        golden_apple: 'golden-apple', carrot: 'carrot', golden_carrot: 'golden-carrot', nether_wart: 'nether-wart',
        blaze_powder: 'blaze-powder', magma_cream: 'magma-cream', potion: 'potion', minecart: 'minecart',
        tnt: 'tnt', command_block: 'command-block'
    };

    const items = Array.isArray(data.items) ? data.items : [];
    const itemById = new Map(items.map(function (item) { return [item.id, item]; }));
    const cells = new Array(9).fill(null);
    let output = null;
    let mode = 'shaped';
    let selectedItem = null;
    let selectedSlot = null;
    let recipeId = data.recipe && data.recipe.id ? Number(data.recipe.id) : 0;
    let renderTimer = 0;

    const grid = root.querySelector('[data-input-grid]');
    const outputSlot = root.querySelector('[data-output-slot]');
    const nameInput = root.querySelector('[data-recipe-name]');
    const selectedCountInput = root.querySelector('[data-selected-count]');
    const outputCountInput = root.querySelector('[data-output-count]');
    const itemGrid = root.querySelector('[data-item-grid]');
    const itemSearch = root.querySelector('[data-item-search]');
    const selectedLabel = root.querySelector('[data-selected-item]');
    const previewImage = root.querySelector('[data-thumbnail-preview]');
    const previewEmpty = root.querySelector('[data-thumbnail-empty]');
    const saveButton = document.querySelector('[data-save-recipe]');
    const generateButton = document.querySelector('[data-generate-thumbnail]');

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (char) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[char];
        });
    }

    function normalizeCount(value) {
        return Math.max(1, Math.min(999, Number.parseInt(value, 10) || 1));
    }

    function slugFromId(itemId) {
        return String(itemId || '').replace(/^minecraft:/, '').replace(/[^a-z0-9_]/g, '');
    }

    function displayName(itemId) {
        const item = itemById.get(itemId);
        return item ? item.name : slugFromId(itemId).replace(/_/g, ' ');
    }

    function iconUrl(itemId) {
        const slug = slugFromId(itemId);
        const iconName = COMMON_ICON_NAMES[slug] || slug.replace(/_/g, '-');
        return 'https://minecraft.wiki/images/ItemSprite_' + encodeURIComponent(iconName) + '.png';
    }

    function itemAbbrev(itemId) {
        const name = displayName(itemId) || slugFromId(itemId);
        const compact = String(name).replace(/\s+/g, '');
        return compact.slice(0, 2).toUpperCase() || '?';
    }

    function stackHtml(stack) {
        if (!stack) return '';
        return '<img src="' + escapeHtml(iconUrl(stack.itemId)) + '" alt="" crossorigin="anonymous" loading="lazy">' +
            '<b>' + escapeHtml(itemAbbrev(stack.itemId)) + '</b>' +
            (stack.count > 1 ? '<small>' + stack.count + '</small>' : '');
    }

    function bindIconFallback(scope) {
        scope.querySelectorAll('img').forEach(function (img) {
            img.addEventListener('error', function () {
                img.hidden = true;
            }, { once: true });
        });
    }

    function makeSlot(index) {
        const slot = document.createElement('div');
        slot.className = 'recipe-slot';
        slot.dataset.slotKind = 'input';
        slot.dataset.index = String(index);
        slot.tabIndex = 0;
        slot.draggable = true;
        slot.setAttribute('aria-label', '输入格 ' + (index + 1));
        return slot;
    }

    function renderSlots() {
        for (let i = 0; i < 9; i++) {
            const slot = grid.querySelector('[data-index="' + i + '"]');
            slot.classList.toggle('filled', !!cells[i]);
            slot.classList.toggle('selected', selectedSlot === 'input:' + i);
            slot.innerHTML = stackHtml(cells[i]);
        }
        outputSlot.classList.toggle('filled', !!output);
        outputSlot.classList.toggle('selected', selectedSlot === 'output');
        outputSlot.innerHTML = stackHtml(output);
        bindIconFallback(root);
    }

    function selectSlot(kind, index) {
        selectedSlot = kind === 'output' ? 'output' : 'input:' + index;
        renderSlots();
    }

    function setStack(kind, index, itemId, count) {
        const stack = { itemId: itemId, count: normalizeCount(count) };
        if (kind === 'output') {
            output = stack;
            outputCountInput.value = String(stack.count);
        } else {
            cells[index] = stack;
        }
        selectedItem = null;
        updateSelectedItemLabel();
        renderSlots();
    }

    function clearSelectedSlot() {
        if (selectedSlot === 'output') {
            output = null;
        } else if (selectedSlot && selectedSlot.startsWith('input:')) {
            cells[Number(selectedSlot.slice(6))] = null;
        }
        renderSlots();
    }

    function updateSelectedItemLabel() {
        selectedLabel.textContent = selectedItem
            ? '已选择：' + displayName(selectedItem.id) + ' (' + selectedItem.id + ')'
            : '未选择物品';
        itemGrid.querySelectorAll('.recipe-item').forEach(function (button) {
            button.classList.toggle('selected', selectedItem && button.dataset.itemId === selectedItem.id);
        });
    }

    function renderItems() {
        const query = itemSearch.value.trim().toLowerCase();
        const filtered = items.filter(function (item) {
            if (!query) return true;
            return String(item.id).toLowerCase().includes(query) || String(item.name || '').toLowerCase().includes(query);
        }).slice(0, 260);
        itemGrid.innerHTML = filtered.map(function (item) {
            return '<button class="recipe-item" type="button" draggable="true" data-item-id="' + escapeHtml(item.id) + '" title="' + escapeHtml(item.name + ' / ' + item.id) + '">' +
                '<img src="' + escapeHtml(iconUrl(item.id)) + '" alt="" crossorigin="anonymous" loading="lazy">' +
                '<b>' + escapeHtml(itemAbbrev(item.id)) + '</b>' +
                '<span>' + escapeHtml(item.name || item.id) + '</span>' +
                '</button>';
        }).join('');
        bindIconFallback(itemGrid);
        updateSelectedItemLabel();
    }

    function setMode(nextMode) {
        mode = nextMode === 'shapeless' ? 'shapeless' : 'shaped';
        document.querySelectorAll('[data-mode]').forEach(function (button) {
            button.classList.toggle('active', button.dataset.mode === mode);
        });
    }

    function hydrateRecipe(recipe) {
        if (!recipe) return;
        nameInput.value = recipe.name || '';
        setMode(recipe.type || 'shaped');
        output = recipe.output || null;
        outputCountInput.value = output ? String(output.count || 1) : '1';
        if (mode === 'shapeless') {
            (recipe.input || []).slice(0, 9).forEach(function (stack, index) {
                cells[index] = stack || null;
            });
        } else {
            (recipe.input || []).forEach(function (row, rowIndex) {
                (row || []).forEach(function (stack, colIndex) {
                    const index = rowIndex * 3 + colIndex;
                    if (index >= 0 && index < 9) cells[index] = stack || null;
                });
            });
        }
        if (recipe.thumbnail_url) {
            previewImage.src = recipe.thumbnail_url;
            previewImage.hidden = false;
            previewEmpty.hidden = true;
        }
    }

    function collectPayload() {
        if (!output) {
            throw new Error('请先设置输出物品。');
        }
        output.count = normalizeCount(outputCountInput.value);
        const payload = {
            name: nameInput.value.trim(),
            type: mode,
            output: output,
            input: null,
            csrf_token: data.csrf || ''
        };
        if (!payload.name) {
            payload.name = displayName(output.itemId);
        }
        if (mode === 'shaped') {
            payload.input = [
                cells.slice(0, 3),
                cells.slice(3, 6),
                cells.slice(6, 9)
            ];
        } else {
            const merged = new Map();
            cells.filter(Boolean).forEach(function (stack) {
                merged.set(stack.itemId, (merged.get(stack.itemId) || 0) + normalizeCount(stack.count));
            });
            payload.input = Array.from(merged.entries()).map(function (entry) {
                return { itemId: entry[0], count: entry[1] };
            });
        }
        if (cells.filter(Boolean).length < 1) {
            throw new Error('请至少放入一个输入物品。');
        }
        return payload;
    }

    function showToast(message, isError) {
        const toast = document.createElement('div');
        toast.className = 'plan-toast' + (isError ? ' error' : '');
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(function () { toast.remove(); }, 2800);
    }

    function hashColor(itemId) {
        let hash = 0;
        for (let i = 0; i < itemId.length; i++) hash = ((hash << 5) - hash + itemId.charCodeAt(i)) | 0;
        const hue = Math.abs(hash) % 360;
        return 'hsl(' + hue + ' 42% 42%)';
    }

    function loadIcon(itemId) {
        return new Promise(function (resolve) {
            const img = new Image();
            const timer = window.setTimeout(function () { resolve(null); }, 1600);
            img.crossOrigin = 'anonymous';
            img.onload = function () {
                window.clearTimeout(timer);
                resolve(img);
            };
            img.onerror = function () {
                window.clearTimeout(timer);
                resolve(null);
            };
            img.src = iconUrl(itemId);
        });
    }

    function drawSlot(ctx, x, y, size, stack, highlight) {
        ctx.fillStyle = highlight ? '#e6d0a7' : '#c8c8c8';
        ctx.fillRect(x, y, size, size);
        ctx.fillStyle = '#8b8b8b';
        ctx.fillRect(x + 3, y + 3, size - 6, size - 6);
        ctx.fillStyle = '#f6f6f6';
        ctx.fillRect(x + 6, y + 6, size - 12, size - 12);
        if (!stack) return Promise.resolve();
        return loadIcon(stack.itemId).then(function (img) {
            if (img) {
                try {
                    ctx.imageSmoothingEnabled = false;
                    ctx.drawImage(img, x + 10, y + 10, size - 20, size - 20);
                } catch (error) {
                    drawFallbackItem(ctx, x, y, size, stack.itemId);
                }
            } else {
                drawFallbackItem(ctx, x, y, size, stack.itemId);
            }
            if (stack.count > 1) {
                ctx.fillStyle = '#111';
                ctx.fillRect(x + size - 24, y + size - 20, 22, 16);
                ctx.fillStyle = '#fff';
                ctx.font = 'bold 13px sans-serif';
                ctx.textAlign = 'right';
                ctx.fillText(String(stack.count), x + size - 5, y + size - 7);
            }
        });
    }

    function drawFallbackItem(ctx, x, y, size, itemId) {
        ctx.fillStyle = hashColor(itemId);
        ctx.fillRect(x + 12, y + 12, size - 24, size - 24);
        ctx.fillStyle = '#fff';
        ctx.font = 'bold 13px sans-serif';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillText(itemAbbrev(itemId), x + size / 2, y + size / 2);
    }

    function canvasToBlob(canvas) {
        return new Promise(function (resolve, reject) {
            try {
                canvas.toBlob(function (blob) {
                    blob ? resolve(blob) : reject(new Error('thumbnail_failed'));
                }, 'image/png');
            } catch (error) {
                reject(error);
            }
        });
    }

    async function makeThumbnailBlob() {
        const canvas = document.createElement('canvas');
        canvas.width = 380;
        canvas.height = 245;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.fillStyle = '#222';
        ctx.font = 'bold 18px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText((nameInput.value.trim() || (output ? displayName(output.itemId) : '未命名配方')).slice(0, 22), 18, 30);
        ctx.fillStyle = '#555';
        ctx.font = '13px sans-serif';
        ctx.fillText(mode === 'shapeless' ? '无序配方' : '有序配方', 18, 52);

        const size = 48;
        const startX = 28;
        const startY = 72;
        const tasks = [];
        for (let i = 0; i < 9; i++) {
            tasks.push(drawSlot(ctx, startX + (i % 3) * size, startY + Math.floor(i / 3) * size, size, cells[i], false));
        }
        ctx.strokeStyle = '#555';
        ctx.lineWidth = 5;
        ctx.beginPath();
        ctx.moveTo(205, 142);
        ctx.lineTo(265, 142);
        ctx.stroke();
        ctx.fillStyle = '#555';
        ctx.beginPath();
        ctx.moveTo(265, 142);
        ctx.lineTo(248, 130);
        ctx.lineTo(248, 154);
        ctx.closePath();
        ctx.fill();
        tasks.push(drawSlot(ctx, 292, 118, 62, output, true));
        await Promise.all(tasks);
        return canvasToBlob(canvas);
    }

    async function uploadThumbnail(id) {
        const blob = await makeThumbnailBlob();
        if (blob.size > 500 * 1024) {
            throw new Error('thumbnail_too_large');
        }
        const form = new FormData();
        form.append('action', 'upload_thumbnail');
        form.append('id', String(id));
        form.append('csrf_token', data.csrf || '');
        form.append('file', blob, 'recipe_' + id + '.png');
        const response = await fetch('/admin/recipes_api.php?action=upload_thumbnail', {
            method: 'POST',
            body: form,
            headers: { 'Accept': 'application/json' }
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.error || 'upload_failed');
        previewImage.src = payload.thumbnail_url + '&t=' + Date.now();
        previewImage.hidden = false;
        previewEmpty.hidden = true;
        return payload;
    }

    async function saveRecipe() {
        let payload;
        try {
            payload = collectPayload();
        } catch (error) {
            showToast(error.message, true);
            return;
        }
        saveButton.disabled = true;
        try {
            const action = recipeId > 0 ? 'update&id=' + encodeURIComponent(String(recipeId)) : 'create';
            const response = await fetch('/admin/recipes_api.php?action=' + action, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok) throw new Error(result.error || 'save_failed');
            recipeId = Number(result.id || recipeId);
            if (recipeId > 0) {
                window.history.replaceState({sitePjax: true}, '', '/admin/recipe_edit.php?id=' + encodeURIComponent(String(recipeId)));
                generateButton.disabled = false;
                try {
                    await uploadThumbnail(recipeId);
                    showToast('配方已保存，缩略图已更新。', false);
                } catch (error) {
                    showToast('配方已保存，但缩略图上传失败，可手动重试。', true);
                }
            }
        } catch (error) {
            showToast('保存失败，请检查配方内容。', true);
        } finally {
            saveButton.disabled = false;
        }
    }

    function setup() {
        for (let i = 0; i < 9; i++) grid.appendChild(makeSlot(i));
        hydrateRecipe(data.recipe);
        renderSlots();
        renderItems();
    }

    root.addEventListener('click', function (event) {
        const itemButton = event.target.closest('.recipe-item');
        if (itemButton) {
            selectedItem = itemById.get(itemButton.dataset.itemId);
            updateSelectedItemLabel();
            return;
        }
        const slot = event.target.closest('.recipe-slot');
        if (slot) {
            const kind = slot.dataset.slotKind;
            const index = Number(slot.dataset.index || 0);
            selectSlot(kind, index);
            if (selectedItem) {
                setStack(kind, index, selectedItem.id, kind === 'output' ? outputCountInput.value : selectedCountInput.value);
            }
        }
    });

    root.addEventListener('contextmenu', function (event) {
        const slot = event.target.closest('.recipe-slot');
        if (!slot) return;
        event.preventDefault();
        if (slot.dataset.slotKind === 'output') output = null;
        else cells[Number(slot.dataset.index || 0)] = null;
        selectedSlot = slot.dataset.slotKind === 'output' ? 'output' : 'input:' + slot.dataset.index;
        renderSlots();
    });

    root.addEventListener('dragstart', function (event) {
        const itemButton = event.target.closest('.recipe-item');
        const slot = event.target.closest('.recipe-slot');
        if (itemButton) {
            event.dataTransfer.setData('application/x-recipe-item', itemButton.dataset.itemId);
            event.dataTransfer.effectAllowed = 'copy';
        } else if (slot && slot.dataset.slotKind === 'input' && cells[Number(slot.dataset.index || 0)]) {
            event.dataTransfer.setData('application/x-recipe-slot', slot.dataset.index);
            event.dataTransfer.effectAllowed = 'move';
        }
    });

    root.addEventListener('dragover', function (event) {
        if (event.target.closest('.recipe-slot')) event.preventDefault();
    });

    root.addEventListener('drop', function (event) {
        const slot = event.target.closest('.recipe-slot');
        if (!slot) return;
        event.preventDefault();
        const kind = slot.dataset.slotKind;
        const index = Number(slot.dataset.index || 0);
        const itemId = event.dataTransfer.getData('application/x-recipe-item');
        const sourceIndex = event.dataTransfer.getData('application/x-recipe-slot');
        if (itemId) {
            setStack(kind, index, itemId, kind === 'output' ? outputCountInput.value : selectedCountInput.value);
        } else if (sourceIndex !== '' && kind === 'input') {
            const from = Number(sourceIndex);
            const old = cells[index];
            cells[index] = cells[from];
            cells[from] = old;
            selectedSlot = 'input:' + index;
            renderSlots();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Delete' && selectedSlot) {
            clearSelectedSlot();
        }
    });

    document.querySelectorAll('[data-mode]').forEach(function (button) {
        button.addEventListener('click', function () { setMode(button.dataset.mode); });
    });
    root.querySelector('[data-clear-slot]').addEventListener('click', clearSelectedSlot);
    root.querySelector('[data-clear-all]').addEventListener('click', function () {
        cells.fill(null);
        output = null;
        selectedSlot = null;
        renderSlots();
    });
    root.querySelector('[data-clear-selection]').addEventListener('click', function () {
        selectedItem = null;
        updateSelectedItemLabel();
    });
    outputCountInput.addEventListener('input', function () {
        if (output) {
            output.count = normalizeCount(outputCountInput.value);
            renderSlots();
        }
    });
    itemSearch.addEventListener('input', function () {
        window.clearTimeout(renderTimer);
        renderTimer = window.setTimeout(renderItems, 90);
    });
    saveButton.addEventListener('click', saveRecipe);
    generateButton.addEventListener('click', async function () {
        if (!recipeId) return;
        generateButton.disabled = true;
        try {
            await uploadThumbnail(recipeId);
            showToast('缩略图已更新。', false);
        } catch (error) {
            showToast('缩略图生成或上传失败。', true);
        } finally {
            generateButton.disabled = false;
        }
    });

    setup();
}());
