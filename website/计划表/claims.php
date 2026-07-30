<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require_once __DIR__.'/../统一认证/auth.php';

if (!auth_is_authenticated()) {
    header('Location: '.auth_login_url('/计划表/claims.php'));
    exit;
}

$user = auth_current_user();
$csrf = auth_csrf_token();
$profile = plan_profile(plan_db(), $user['username']);
$display = $profile['nickname'] ?: $user['username'];
$mc = $profile['minecraft_username'] ?: $user['username'];

try {
    $claims = plan_user_claims(plan_db(), $user['username']);
} catch (Throwable $e) {
    error_log('Plan claims: '.$e->getMessage());
    $claims = [];
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$groups = [];
$totalBoxes = 0.0;
$completedBoxes = 0.0;
$completedCount = 0;
foreach ($claims as $claim) {
    $projectId = (string)$claim['project_id'];
    $litematicId = (string)$claim['litematic_id'];
    $groups[$projectId] ??= ['name' => $claim['project_name'], 'claims' => [], 'projections' => []];
    $groups[$projectId]['claims'][] = $claim;
    $groups[$projectId]['projections'][$litematicId] ??= ['filename' => $claim['filename'], 'claims' => []];
    $groups[$projectId]['projections'][$litematicId]['claims'][] = $claim;
    $totalBoxes += (float)$claim['boxes'];
    if ($claim['collected_at']) {
        $completedBoxes += (float)$claim['boxes'];
        $completedCount++;
    }
}
$pendingCount = count($claims) - $completedCount;
$progress = $totalBoxes > 0 ? (int)round($completedBoxes / $totalBoxes * 100) : 0;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>我的认领 | 计划表 | 示例服务器</title>
<link rel="stylesheet" href="../assets/site.css?v=20260731a">
<script src="/assets/lenis.min.js?v=1.3.25"></script>
<script src="/assets/site-config.php?v=20260724a"></script>
<script src="../assets/site.js?v=20260731a"></script>
</head>
<body class="plans-page">
<header class="topbar"><div class="shell"><a class="brand" href="../">示例服务器</a><nav class="nav" aria-label="站点导航"><a href="../">首页</a><a href="../状态/">实时状态</a><a href="../统计数据/">玩家统计</a><a href="../配方/">配方</a><a href="../附魔计算/">附魔计算</a><a href="../经纬度/">经纬度</a><a href="./" aria-current="page">计划表</a><?php if(auth_is_superadmin()):?><a class="nav-account" href="/admin/">后台</a><?php endif;?><a href="/个人资料/">个人资料</a><form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/计划表/claims.php"><input type="hidden" name="csrf_token" value="<?=h($csrf)?>"><button type="submit">退出</button></form></nav></div></header>
<main class="shell plans-main">
<section class="plans-heading"><div><p class="plans-kicker">MY CLAIMS</p><div class="plan-claim-user"><img src="asset.php?kind=avatar&amp;name=<?=rawurlencode($mc)?>" alt=""><h1><?=h($display)?> 的认领</h1></div><p class="plans-lede">按项目和投影集中跟踪负责的材料。</p></div><a class="plans-primary" href="./">返回项目</a></section>
<section class="plans-summary plan-claims-summary"><div><span>认领记录</span><strong><?=count($claims)?></strong></div><div><span>待收集</span><strong><?=$pendingCount?></strong></div><div><span>已完成</span><strong><?=$completedCount?></strong></div><div><span>收集进度</span><strong><?=$progress?>%</strong></div></section>
<?php if ($claims): ?>
<section class="plan-claims-toolbar"><div class="plans-tabs" data-claim-tabs><button class="active" data-status="all">全部</button><button data-status="pending">待收集</button><button data-status="completed">已完成</button></div><div class="plan-claims-batch"><button type="button" data-select-visible>全选当前筛选</button><button type="button" data-clear-selected disabled>清空选择</button><button type="button" class="plans-primary" data-complete-selected disabled>完成所选</button><button type="button" data-uncomplete-selected disabled>撤销完成</button><button type="button" class="danger" data-cancel-selected disabled>取消认领</button><span data-selected-count>当前筛选 0 条 · 未选择</span></div><label class="plans-search"><span>搜索任务</span><input type="search" data-claim-search placeholder="项目、投影或材料"></label></section>
<div data-claim-groups>
<?php foreach ($groups as $projectId => $projectGroup): ?>
<section class="claim-project-group" data-project-group>
<header class="claim-project-head"><h2><a href="project.php?id=<?=h($projectId)?>"><?=h($projectGroup['name'])?></a></h2><span><?=count($projectGroup['claims'])?> 条 · <?=number_format(array_sum(array_map(fn($item) => (float)$item['boxes'], $projectGroup['claims'])), 3)?> 盒</span></header>
<?php foreach ($projectGroup['projections'] as $projection): ?>
<section class="claim-projection">
<button type="button" data-collapse><span><?=h($projection['filename'])?></span><small><?=count($projection['claims'])?> 项任务</small></button>
<div class="claim-task-list">
<?php foreach ($projection['claims'] as $claim): $done = !empty($claim['collected_at']); ?>
<article class="<?=$done?'completed':'pending'?>" data-claim-item data-state="<?=$done?'completed':'pending'?>" data-search="<?=h(strtolower($projectGroup['name'].' '.$projection['filename'].' '.$claim['display_name'].' '.$claim['block_id']))?>" data-project-id="<?=h($projectId)?>">
<input type="checkbox" data-claim-select data-collected="<?=$done?'1':'0'?>" value="<?=(int)$claim['id']?>" aria-label="选择 <?=h($claim['display_name'])?>">
<img src="asset.php?kind=block&amp;id=<?=rawurlencode($claim['block_id'])?>" alt="" loading="lazy">
<div><strong><?=h($claim['display_name'])?></strong><small><?=h($claim['block_id'])?></small></div>
<span><?=number_format((float)$claim['boxes'], 3)?> 盒</span>
<button type="button" data-toggle="<?=(int)$claim['id']?>"><?=$done?'撤销完成':'标记完成'?></button>
</article>
<?php endforeach; ?>
</div>
</section>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
</div>
<section class="plan-empty" data-no-results hidden>没有匹配的认领任务。</section>
<?php else: ?>
<section class="plan-empty">你还没有认领材料。</section>
<?php endif; ?>
  <section class="plan-create" data-batch-confirm hidden><div class="plan-create-panel plan-confirm-panel" role="dialog" aria-modal="true"><h2>确认批量操作</h2><p data-batch-confirm-message></p><div class="plan-modal-actions"><button type="button" data-batch-cancel>返回</button><button type="button" class="plans-primary" data-batch-ok>确认</button></div></div></section><div class="plan-toast" data-toast hidden></div>
</main>
<footer class="site-footer"><div class="shell"><span>示例服务器 · <a href="about.php">关于计划表</a></span><div class="filing"></div></div></footer>
<script>(function(){
const csrf='<?=h($csrf)?>',toastBox=document.querySelector('[data-toast]');let status='all',query='';
function toast(message){toastBox.textContent=message;toastBox.hidden=false;clearTimeout(toastBox.timer);toastBox.timer=setTimeout(()=>toastBox.hidden=true,2400)}
function filter(){let shown=0;document.querySelectorAll('[data-claim-item]').forEach(item=>{let visible=(status==='all'||item.dataset.state===status)&&(!query||item.dataset.search.includes(query));item.hidden=!visible;if(visible)shown++});document.querySelectorAll('[data-project-group]').forEach(group=>group.hidden=!group.querySelector('[data-claim-item]:not([hidden])'));document.querySelectorAll('.claim-projection').forEach(group=>{let has=group.querySelector('[data-claim-item]:not([hidden])');group.hidden=!has});let empty=document.querySelector('[data-no-results]');if(empty)empty.hidden=shown>0;updateSelected()}
document.querySelectorAll('[data-claim-tabs] button').forEach(button=>button.onclick=()=>{status=button.dataset.status;document.querySelectorAll('[data-claim-tabs] button').forEach(item=>item.classList.toggle('active',item===button));filter()});
const search=document.querySelector('[data-claim-search]');if(search)search.oninput=()=>{query=search.value.trim().toLowerCase();filter()};
document.querySelectorAll('[data-collapse]').forEach(button=>button.onclick=()=>button.closest('.claim-projection').classList.toggle('collapsed'));
 const checks=[...document.querySelectorAll('[data-claim-select]')],completeButton=document.querySelector('[data-complete-selected]'),uncompleteButton=document.querySelector('[data-uncomplete-selected]'),cancelButton=document.querySelector('[data-cancel-selected]'),selectButton=document.querySelector('[data-select-visible]'),clearButton=document.querySelector('[data-clear-selected]'),selectedCount=document.querySelector('[data-selected-count]');function visibleChecks(){return checks.filter(x=>!x.closest('[data-claim-item]').hidden)}function updateSelected(){if(!completeButton)return;let selected=checks.filter(x=>x.checked),pending=selected.filter(x=>x.dataset.collected==='0'),completed=selected.filter(x=>x.dataset.collected==='1'),visible=visibleChecks(),allVisible=visible.length>0&&visible.every(x=>x.checked),statusNames={all:'全部状态',pending:'待收集',completed:'已完成'},scope=statusNames[status]||status;if(query)scope+=' · “'+query+'”';checks.forEach(x=>x.closest('[data-claim-item]').classList.toggle('selected',x.checked));completeButton.disabled=pending.length===0;uncompleteButton.disabled=completed.length===0;cancelButton.disabled=pending.length===0;clearButton.disabled=selected.length===0;selectButton.disabled=visible.length===0;selectButton.textContent=allVisible?'取消全选':'全选当前筛选';selectedCount.textContent='筛选：'+scope+' · '+visible.length+' 条 · 已选 '+selected.length+' 条'}if(completeButton){checks.forEach(x=>x.onchange=updateSelected);document.querySelectorAll('[data-claim-item]').forEach(row=>row.onclick=e=>{if(e.target.closest('button,a,input,label,select,textarea'))return;let check=row.querySelector('[data-claim-select]');check.checked=!check.checked;updateSelected()});selectButton.onclick=()=>{let visible=visibleChecks(),select=visible.some(x=>!x.checked);visible.forEach(x=>x.checked=select);updateSelected()};clearButton.onclick=()=>{checks.forEach(x=>x.checked=false);updateSelected()};const batchConfirm=document.querySelector('[data-batch-confirm]');let batchResolve;function askBatch(message){batchConfirm.querySelector('[data-batch-confirm-message]').textContent=message;batchConfirm.hidden=false;return new Promise(resolve=>batchResolve=resolve)}batchConfirm.querySelector('[data-batch-cancel]').onclick=()=>{batchConfirm.hidden=true;batchResolve?.(false)};batchConfirm.querySelector('[data-batch-ok]').onclick=()=>{batchConfirm.hidden=true;batchResolve?.(true)};async function setCollected(collected){let selected=checks.filter(x=>x.checked&&x.dataset.collected===(collected?'0':'1'));if(!selected.length)return;if(!await askBatch((collected?'将所选 ':'撤销所选 ')+selected.length+' 条认领记录的完成状态？'))return;completeButton.disabled=true;uncompleteButton.disabled=true;try{let groups={};selected.forEach(x=>{let projectId=x.closest('[data-claim-item]').dataset.projectId;(groups[projectId]??=[]).push(Number(x.value))});for(let [projectId,ids] of Object.entries(groups)){let response=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'batch_set_collected',project_id:projectId,claim_ids:ids,collected:collected,csrf_token:csrf})}),value=await response.json();if(!response.ok)throw new Error(value.error||'批量操作失败')}location.reload()}catch(error){toast(error.message);updateSelected()}}async function cancelSelected(){let selected=checks.filter(x=>x.checked&&x.dataset.collected==='0');if(!selected.length)return;if(!await askBatch('取消所选 '+selected.length+' 条认领记录？'))return;cancelButton.disabled=true;try{let groups={};selected.forEach(x=>{let projectId=x.closest('[data-claim-item]').dataset.projectId;(groups[projectId]??=[]).push(Number(x.value))});for(let [projectId,ids] of Object.entries(groups)){let response=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'batch_delete_claims',project_id:projectId,claim_ids:ids,csrf_token:csrf})}),value=await response.json();if(!response.ok)throw new Error(value.error||'取消认领失败')}location.reload()}catch(error){toast(error.message);updateSelected()}}completeButton.onclick=()=>setCollected(true);uncompleteButton.onclick=()=>setCollected(false);cancelButton.onclick=cancelSelected;updateSelected()}
document.querySelectorAll('[data-toggle]').forEach(button=>button.onclick=async()=>{button.disabled=true;try{let row=button.closest('[data-claim-item]'),response=await fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'toggle_collected',project_id:row.dataset.projectId,claim_id:button.dataset.toggle,csrf_token:csrf})}),value=await response.json();if(!response.ok)throw new Error(value.error||'操作失败');location.reload()}catch(error){toast(error.message);button.disabled=false}});
})();</script>
</body>
</html>
