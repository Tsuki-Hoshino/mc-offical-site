<?php
declare(strict_types=1);
require __DIR__.'/db.php';require_once __DIR__.'/../统一认证/auth.php';
$user=auth_is_authenticated()?auth_current_user():null;$csrf=auth_csrf_token();
$id=trim((string)($_GET['id']??''));try{$project=plan_project(plan_db(),$id);}catch(Throwable $e){error_log('Plan project: '.$e->getMessage());$project=null;}
if(!$project){http_response_code(404);$project=['name'=>'项目不存在','description'=>'该项目不存在或数据库尚未初始化。','owner_username'=>'','status'=>'','about'=>'','litematics'=>[],'members'=>[]];}
function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function material_category(string $id):string{$id=str_replace('minecraft:','',$id);if(preg_match('/redstone|piston|observer|repeater|comparator|hopper|dispenser|dropper|rail|target|lever|button|pressure_plate|tripwire/',$id))return 'redstone';if(preg_match('/concrete|terracotta|wool|carpet|stained_glass|glazed/',$id))return 'color';if(preg_match('/log|wood|planks|stone|brick|slab|stairs|wall|fence|glass|quartz|deepslate|copper/',$id))return 'building';if(preg_match('/dirt|grass|sand|gravel|ore|leaves|sapling|flower|coral|ice|snow|moss|mud|netherrack|basalt|end_stone/',$id))return 'natural';if(preg_match('/chest|barrel|furnace|crafting|anvil|lantern|torch|bed|door|trapdoor|sign|ladder|scaffolding|shulker/',$id))return 'utility';return 'other';}
$memberRole=null;foreach($project['members'] as$m)if($user&&$m['username']===$user['username'])$memberRole=$m['role'];
$canManage=$user&&($user['username']===$project['owner_username']||auth_is_site_admin()||$memberRole==='admin');
$canDelete=$user&&($user['username']===$project['owner_username']||auth_is_site_admin());
$canClaim=$user&&(auth_is_site_admin()||$memberRole!==null);
$memberNames=array_fill_keys(array_map(fn($m)=>(string)$m['username'],$project['members']),true);$availableUsers=[];if($canManage){try{$availableUsers=array_values(array_filter(auth_db()->query("SELECT username,role FROM users WHERE enabled=1 ORDER BY role='superadmin' DESC, username")->fetchAll(),fn($u)=>!isset($memberNames[(string)$u['username']])));}catch(Throwable $e){error_log('Plan member user list: '.$e->getMessage());$availableUsers=[];}}
$totalBlocks=array_sum(array_map(fn($l)=>(int)$l['total_blocks'],$project['litematics']));
$memberProfiles=[];foreach($project['members'] as$m)$memberProfiles[$m['username']]=plan_profile(plan_db(),$m['username']);$ownerProfile=plan_profile(plan_db(),$project['owner_username']);
$materialTotals=[];$allClaims=[];$litematicStats=[];$materialIds=[];
foreach($project['litematics'] as$l){$ltotal=0;$lclaimed=0;$lcollected=0;foreach($l['materials'] as$m){$key=$m['block_id'];$materialIds[$key]=true;if(!isset($materialTotals[$key]))$materialTotals[$key]=['id'=>$key,'name'=>$m['display_name'],'count'=>0,'boxes'=>0,'claimed'=>0,'collected'=>0];$materialTotals[$key]['count']+=(int)$m['block_count'];$materialTotals[$key]['boxes']+=(float)$m['boxes'];$ltotal+=(float)$m['boxes'];}foreach($l['claims'] as$c){$allClaims[]=$c;$lclaimed+=(float)$c['boxes'];if($c['collected_at'])$lcollected+=(float)$c['boxes'];$mid=(int)$c['material_id'];foreach($l['materials'] as$m)if((int)$m['id']===$mid){$materialTotals[$m['block_id']]['claimed']+=(float)$c['boxes'];if($c['collected_at'])$materialTotals[$m['block_id']]['collected']+=(float)$c['boxes'];break;}}$litematicStats[$l['id']]=['total'=>$ltotal,'claimed'=>$lclaimed,'collected'=>$lcollected,'claim_progress'=>$ltotal>0?min(100,(int)round($lclaimed/$ltotal*100)):0,'collect_progress'=>$lclaimed>0?min(100,(int)round($lcollected/$lclaimed*100)):0];}
$totalTypes=count($materialIds);$totalBoxes=array_sum(array_column($materialTotals,'boxes'));$claimedBoxes=array_sum(array_column($materialTotals,'claimed'));$collectedBoxes=array_sum(array_column($materialTotals,'collected'));$claimProgress=$totalBoxes>0?min(100,(int)round($claimedBoxes/$totalBoxes*100)):0;$collectProgress=$claimedBoxes>0?min(100,(int)round($collectedBoxes/$claimedBoxes*100)):0;$claimedTypes=count(array_filter($materialTotals,fn($m)=>$m['claimed']>0));
$memberStats=[];foreach($project['members'] as$m){$claims=array_values(array_filter($allClaims,fn($c)=>$c['claimant']===$m['username']));$boxes=array_sum(array_map(fn($c)=>(float)$c['boxes'],$claims));$done=array_sum(array_map(fn($c)=>$c['collected_at']?(float)$c['boxes']:0,$claims));$profile=$memberProfiles[$m['username']];$memberStats[]=$m+['nickname'=>$profile['nickname'],'minecraft_username'=>$profile['minecraft_username'],'claim_count'=>count($claims),'claimed_boxes'=>$boxes,'collected_boxes'=>$done,'contribution'=>$claimedBoxes>0?(int)round($boxes/$claimedBoxes*100):0,'completion'=>$boxes>0?(int)round($done/$boxes*100):0];}usort($memberStats,fn($a,$b)=>$b['claimed_boxes']<=>$a['claimed_boxes']);
$activeMembers=count(array_filter($memberStats,fn($m)=>$m['claimed_boxes']>0));$topContributor=$memberStats[0]??null;$myClaims=$user?array_values(array_filter($allClaims,fn($c)=>$c['claimant']===$user['username'])):[];$myBoxes=array_sum(array_map(fn($c)=>(float)$c['boxes'],$myClaims));$myCollected=array_sum(array_map(fn($c)=>$c['collected_at']?(float)$c['boxes']:0,$myClaims));
uasort($materialTotals,fn($a,$b)=>$b['count']<=>$a['count']);$topChart=array_slice(array_values($materialTotals),0,10);$dimension=['主世界'=>0,'下界'=>0,'末地'=>0];foreach($materialTotals as$key=>$m){$short=str_replace('minecraft:','',$key);$where=preg_match('/nether|quartz|blackstone|basalt|soul|crimson|warped|netherrack|glowstone|magma/',$short)?'下界':(preg_match('/end_|purpur|chorus|shulker|dragon/',$short)?'末地':'主世界');$dimension[$where]+=$m['count'];}
$claimTop=array_values(array_filter($materialTotals,fn($m)=>$m['claimed']>0));usort($claimTop,fn($a,$b)=>$b['claimed']<=>$a['claimed']);$claimTop=array_slice($claimTop,0,10);$needsHelp=array_values(array_filter($materialTotals,fn($m)=>$m['boxes']-$m['claimed']>0));usort($needsHelp,fn($a,$b)=>($b['boxes']-$b['claimed'])<=>($a['boxes']-$a['claimed']));$mostClaimed=$claimTop[0]??null;$mostNeeded=$needsHelp[0]??null;
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="theme-color" content="#f9a8d4">
<title>
<?=h($project['name'])?> | 计划表 | 示例服务器</title>
<link rel="stylesheet" href="../assets/site.css?v=20260731a">
<script src="/assets/lenis.min.js?v=1.3.25">
</script>
<script src="../assets/chart.umd.min.js?v=4.5.1">
</script>
<script src="/assets/site-config.php?v=20260724a">
</script>
<script src="../assets/site.js?v=20260731a">
</script>
</head>
<body class="plans-page plan-detail-page">
<header class="topbar">
<div class="shell">
<a class="brand" href="../">示例服务器</a>
<nav class="nav" aria-label="站点导航">
<a href="../">首页</a>
<a href="../状态/">实时状态</a>
<a href="../统计数据/">玩家统计</a>
<a href="../配方/">配方</a>
<a href="../附魔计算/">附魔计算</a>
<a href="../经纬度/">经纬度</a>
<a href="./" aria-current="page">计划表</a>
<?php if($user):?>
<?php if(auth_is_superadmin()):?><a class="nav-account" href="/admin/">后台</a><?php endif;?>
<a href="/个人资料/">个人资料</a>
<form class="machine-logout" method="post" action="/统一认证/logout.php"><input type="hidden" name="next" value="/计划表/"><input type="hidden" name="csrf_token" value="<?=h($csrf)?>"><button type="submit">退出</button></form>
<?php endif;?>
</nav>
</div>
</header>
<main class="shell plan-detail">
<a class="plan-back" href="./">← 返回计划表</a>
<section class="plan-detail-head">
<div>
<div class="plan-detail-meta">
<span class="plan-status <?=h($project['status'])?>">
<?=h(['active'=>'进行中','paused'=>'已暂停','completed'=>'已完成'][$project['status']]??'未知')?>
</span>
<span class="plan-owner"><img src="asset.php?kind=avatar&amp;name=<?=rawurlencode($ownerProfile['minecraft_username']?:$project['owner_username'])?>" alt="">发起人 <?=h($ownerProfile['nickname']?:$project['owner_username'])?></span>
</div>
<h1>
<?=h($project['name'])?>
</h1>
<p>
<?=h($project['description'])?>
</p>
</div>
<div class="plan-detail-actions">
<button type="button" data-share>分享</button>
<?php if($canManage):?>
<button data-upload-open>上传投影</button>
<button data-edit-open>项目设置</button>
<?php elseif(!$user):?>
<a href="<?=h(auth_login_url('/计划表/project.php?id='.$id))?>">验证后认领</a>
<?php endif;?>
</div>
</section>
<nav class="plan-view-tabs" aria-label="项目视图">
<button class="active" data-view-button="overview">概况</button>
<button data-view-button="about">关于</button>
<button data-view-button="materials">材料列表</button>
<button data-view-button="members">参与人员</button>
<button data-view-button="stats">统计</button>
</nav>
<section data-view-panel="overview">
<section class="plans-summary plan-detail-summary">
<div>
<span>投影</span>
<strong>
<?=count($project['litematics'])?>
</strong>
</div>
<div>
<span>材料种类</span>
<strong>
<?=number_format($totalTypes)?>
</strong>
</div>
<div>
<span>方块总数</span>
<strong>
<?=number_format($totalBlocks)?>
</strong>
</div>
</section>
<section class="plan-overview-hero">
<div class="plan-overview-main"><strong><?=$claimProgress?><small>%</small></strong><span>整体认领进度</span><p>共 <?=number_format($totalBoxes,3)?> 盒 · 覆盖 <?=$totalTypes?> 种材料</p><div class="plan-dual-progress"><i style="width:<?=$claimProgress?>%"></i><b style="width:<?=min(100,$totalBoxes>0?$collectedBoxes/$totalBoxes*100:0)?>%"></b></div><small>认领 <?=$claimProgress?>% · 收集 <?=$collectProgress?>%</small></div>
<div class="plan-overview-counts"><span>已认领<strong><?=number_format($claimedBoxes,3)?></strong>盒</span><span>已收集<strong><?=number_format($collectedBoxes,3)?></strong>盒</span><span>待认领<strong><?=number_format(max(0,$totalBoxes-$claimedBoxes),3)?></strong>盒</span></div>
</section>
<section class="plan-overview-facts"><span>投影<strong><?=count($project['litematics'])?></strong>个</span><span>成员<strong><?=count($project['members'])?></strong>人 · <?=$activeMembers?> 活跃</span><span>材料覆盖<strong><?=$claimedTypes?></strong>/ <?=$totalTypes?> 种</span><span>方块总数<strong><?=number_format($totalBlocks)?></strong></span></section>
<?php if($user&&$myClaims):?><section class="plan-my-contribution"><div><span>我的贡献</span><strong><?=number_format($myBoxes,3)?> 盒</strong></div><div><span>已完成 <?=number_format($myCollected,3)?> 盒</span><span>待完成 <?=number_format(max(0,$myBoxes-$myCollected),3)?> 盒</span></div><a href="claims.php">查看我的认领</a></section><?php endif;?>
<section class="plan-projection-progress"><div class="plan-section-head"><div><h2>投影进度</h2><p><?=count($project['litematics'])?> 个投影</p></div></div><?php foreach($project['litematics'] as$index=>$l):$ls=$litematicStats[$l['id']];?><article><span><?=str_pad((string)($index+1),2,'0',STR_PAD_LEFT)?></span><div><strong><?=h($l['filename'])?></strong><small><?=number_format((int)$l['total_types'])?> 种 · <?=number_format((int)$l['total_blocks'])?> 方块</small></div><div class="plan-projection-bars"><i style="width:<?=$ls['claim_progress']?>%"></i><b style="width:<?=min(100,$ls['total']>0?$ls['collected']/$ls['total']*100:0)?>%"></b></div><small>认领 <?=$ls['claim_progress']?>% · 收集 <?=$ls['collect_progress']?>%</small></article><?php endforeach;?></section>
</section>
<section data-view-panel="stats" hidden>
<section class="plan-stat-progress"><article><span>认领完成度</span><strong><?=$claimProgress?>%</strong><small><?=number_format($claimedBoxes,3)?> / <?=number_format($totalBoxes,3)?> 盒</small></article><article><span>收集完成度</span><strong><?=$collectProgress?>%</strong><small><?=number_format($collectedBoxes,3)?> / <?=number_format($claimedBoxes,3)?> 盒</small></article><article><span>核心数据</span><strong><?=$activeMembers?> 人</strong><small><?=$claimedTypes?> 种材料 · <?=count($allClaims)?> 条认领</small></article></section>
<?php if($materialTotals):?><section class="material-charts"><article><h2>材料数量 Top 10</h2><canvas id="material-top-chart"></canvas></article><article><h2>材料来源分布</h2><canvas id="material-dimension-chart"></canvas></article><article class="storage-chart"><h2>存储需求</h2><div><span><strong><?=number_format(array_sum(array_column($materialTotals,'boxes')),1)?></strong>潜影盒</span><span><strong><?=number_format((int)ceil(array_sum(array_column($materialTotals,'boxes'))/54))?></strong>大箱子</span><span><strong><?=number_format(array_sum(array_map(fn($m)=>$m['count']/64,$materialTotals)),1)?></strong>总组数</span></div><ol><?php foreach(array_slice(array_values($materialTotals),0,5)as$m):?><li><span><?=h($m['name'])?></span><b><?=number_format($m['boxes'],3)?> 盒</b></li><?php endforeach;?></ol></article></section><?php endif;?>
<section class="plan-analytics-grid"><article><h2>贡献占比</h2><div class="plan-ranking"><?php foreach($memberStats as$m):if($m['claimed_boxes']<=0)continue;?><div><span><img src="asset.php?kind=avatar&amp;name=<?=rawurlencode($m['minecraft_username']?:$m['username'])?>" alt=""><?=h($m['nickname']?:$m['username'])?></span><i><b style="width:<?=$m['contribution']?>%"></b></i><strong><?=number_format($m['claimed_boxes'],3)?> 盒 · <?=$m['contribution']?>%</strong></div><?php endforeach;?></div></article><article><h2>材料认领 Top 10</h2><div class="plan-ranking"><?php foreach($claimTop as$m):$pct=$m['boxes']>0?min(100,(int)round($m['claimed']/$m['boxes']*100)):0;?><div><span><img src="asset.php?kind=block&amp;id=<?=rawurlencode($m['id'])?>" alt=""><?=h($m['name'])?></span><i><b style="width:<?=$pct?>%"></b></i><strong><?=number_format($m['claimed'],3)?> / <?=number_format($m['boxes'],3)?></strong></div><?php endforeach;?></div></article></section>
<section class="plan-key-materials"><article><span>最热门</span><strong><?=h($mostClaimed['name']??'暂无')?></strong><small><?=$mostClaimed?number_format($mostClaimed['claimed'],3).' 盒已认领':'还没有认领记录'?></small></article><article><span>最需帮助</span><strong><?=h($mostNeeded['name']??'暂无')?></strong><small><?=$mostNeeded?number_format(max(0,$mostNeeded['boxes']-$mostNeeded['claimed']),3).' 盒剩余':'材料均已认领'?></small></article><article><span>贡献最高</span><strong><?=h($topContributor?($topContributor['nickname']?:$topContributor['username']):'暂无')?></strong><small><?=$topContributor?number_format($topContributor['claimed_boxes'],3).' 盒 · '.$topContributor['contribution'].'%':'还没有贡献记录'?></small></article></section>
<section class="plan-projection-progress"><div class="plan-section-head"><div><h2>投影完成度</h2></div></div><?php foreach($project['litematics'] as$index=>$l):$ls=$litematicStats[$l['id']];?><article><span><?=str_pad((string)($index+1),2,'0',STR_PAD_LEFT)?></span><div><strong><?=h($l['filename'])?></strong><small><?=number_format((int)$l['total_types'])?> 种 · <?=number_format((int)$l['total_blocks'])?> 方块</small></div><div class="plan-projection-bars"><i style="width:<?=$ls['claim_progress']?>%"></i><b style="width:<?=min(100,$ls['total']>0?$ls['collected']/$ls['total']*100:0)?>%"></b></div><small>认领 <?=$ls['claim_progress']?>% · 收集 <?=$ls['collect_progress']?>%</small></article><?php endforeach;?></section>
</section>
<section data-view-panel="about" hidden>
<section class="plan-about">
<div class="plan-section-head"><div><h2>关于项目</h2><p>项目介绍、施工说明、坐标、规则和注意事项</p></div><?php if($canManage):?><button type="button" data-edit-open>编辑</button><?php endif;?></div>
<div>
<?=$project['about']?nl2br(h($project['about'])):'<span class="plan-muted">还没有填写项目说明。</span>'?>
</div>
</section>
</section>
<section data-view-panel="members" hidden>
<section class="plan-members">
<div class="plan-section-head">
<div>
<h2>协作成员</h2>
<p>只有项目成员可以认领材料；管理员可上传投影和维护成员。</p>
</div>
<?php if($canManage):?>
<form data-member-form>
<select name="username" required>
<option value="">选择已有账户</option>
<?php foreach($availableUsers as$availableUser):?><option value="<?=h($availableUser['username'])?>"><?=h($availableUser['username'])?><?=($availableUser['role']??'')==='superadmin'?' · 超级管理员':' · 编辑账户'?></option><?php endforeach;?>
</select>
<select name="role">
<option value="member">成员</option>
<option value="admin">项目管理员</option>
</select>
<button <?=$availableUsers?'':'disabled'?>>添加</button>
</form>
<?php endif;?>
</div>
<div class="plan-member-table">
<?php foreach($memberStats as$m):?>
<article>
<img class="member-avatar" src="asset.php?kind=avatar&amp;name=<?=rawurlencode($m['minecraft_username']?:$m['username'])?>" alt="">
<div><strong><?=h($m['nickname']?:$m['username'])?></strong><small><?=h($m['username'])?> · <?=h(['owner'=>'创建者','admin'=>'项目管理员','member'=>'成员'][$m['role']]??$m['role'])?></small></div>
<span>认领<strong><?=number_format($m['claimed_boxes'],3)?> 盒</strong></span><span>完成<strong><?=number_format($m['collected_boxes'],3)?> 盒 · <?=$m['completion']?>%</strong></span><span>贡献<strong><?=$m['contribution']?>%</strong></span>
<?php if($m['role']!=='owner'&&($canManage||($user&&$m['username']===$user['username']))):?><button data-remove-member="<?=h($m['username'])?>"><?=$user&&$m['username']===$user['username']?'退出项目':'移除'?></button><?php endif;?>
</article>
<?php endforeach;?>
</div>
</section>
</section>
<section data-view-panel="materials" hidden>
<section class="plan-material-browser">
<div class="plan-section-head"><div><h2>材料清单</h2><p>按投影、认领状态和材料类别查看</p></div><input type="search" data-global-material-search placeholder="搜索材料或方块 ID"></div>
<div class="plan-filter-row"><div data-claim-filter><button class="active" data-value="all">全部</button><button data-value="unclaimed">未认领</button><button data-value="claimed">已认领</button></div><div data-category-filter><button class="active" data-value="all">全部</button><button data-value="building">建筑方块</button><button data-value="color">彩色方块</button><button data-value="natural">自然方块</button><button data-value="utility">功能方块</button><button data-value="redstone">红石</button><button data-value="other">其他</button></div></div>
<?php if(count($project['litematics'])>1):?><div class="plan-litematic-tabs"><?php foreach($project['litematics'] as$index=>$l):?><button class="<?=$index===0?'active':''?>" data-lit-tab="<?=h($l['id'])?>" title="<?=h($l['filename'])?>"><?=h($l['filename'])?></button><?php endforeach;?></div><?php endif;?>
</section>
<?php if(!$project['litematics']):?>
<section class="plan-empty">还没有投影文件。项目管理者可以上传 `.litematic` 开始统计材料。</section>
<?php endif;?>
<section class="litematic-list">
<?php foreach($project['litematics'] as$index=>$l):?>
<article class="litematic-panel" data-litematic="<?=h($l['id'])?>" data-lit-panel <?=($index>0?'hidden':'')?>>
<header>
<div>
<span class="plans-kicker">LITEMATIC <?=($index+1)?>
</span>
<h2>
<?=h($l['filename'])?>
</h2>
<p>
<?=number_format((int)$l['total_types'])?> 种材料 · <?=number_format((int)$l['total_blocks'])?> 个方块</p>
</div>
<div>
<?php if($canManage):?>
<button class="plan-danger" data-delete-litematic="<?=h($l['id'])?>">删除投影</button>
<?php endif;?>
</div>
</header>
<div class="material-tools"><?php if($canClaim):?><div class="plan-batch-actions"><button type="button" data-select-page>全选当前筛选</button><button type="button" data-clear-selection>清空选择</button><button type="button" data-batch-claim disabled>认领所选</button><button type="button" class="primary" data-batch-claim-complete disabled>认领并完成</button><button type="button" data-batch-complete disabled>完成所选</button><button type="button" data-batch-uncomplete disabled>撤销完成</button><button type="button" class="danger" data-batch-delete-claims disabled>取消认领</button><span data-batch-summary>当前筛选 0 项 · 未选择</span></div><?php else:?><div class="material-legend"><span>需求</span><span>已认领</span><span>已收集</span></div><?php endif;?></div>
<div class="material-table">
<table>
<thead>
<tr>
<th>材料</th>
<th>数量</th>
<th>盒</th>
<th>组</th>
<th>认领进度</th>
<th>操作</th>
</tr>
</thead>
<?php foreach($l['materials'] as$m):$claimed=(float)$m['claimed_boxes'];$boxes=(float)$m['boxes'];$remainingBoxes=max(0,$boxes-$claimed);$percent=$boxes?min(100,$claimed/$boxes*100):0;$materialClaims=array_values(array_filter($l['claims'],fn($c)=>(int)$c['material_id']===(int)$m['id']));?>
<tbody data-material-item data-category="<?=h(material_category($m['block_id']))?>" data-claim-state="<?=$remainingBoxes<=0.0000001?'claimed':'unclaimed'?>" data-name="<?=h(strtolower($m['display_name'].' '.$m['block_id']))?>">
<tr data-material-row <?=$canClaim&&$remainingBoxes>0.0000001?'data-material-select-row':''?> data-name="<?=h(strtolower($m['display_name'].' '.$m['block_id']))?>">
<td>
<div class="material-name">
<?php if($canClaim&&$remainingBoxes>0.0000001):?><input type="checkbox" data-batch-material value="<?=(int)$m['id']?>" aria-label="选择 <?=h($m['display_name'])?>"><?php endif;?>
<img src="asset.php?kind=block&amp;id=<?=rawurlencode($m['block_id'])?>" alt="" width="34" height="34" loading="lazy">
<span>
<strong>
<?=h($m['display_name'])?>
</strong>
<small>
<?=h($m['block_id'])?>
</small>
</span>
</div>
</td>
<td>
<?=number_format((int)$m['block_count'])?>
</td>
<td>
<?=number_format($boxes,3)?>
</td>
<td>
<?=number_format((float)$m['stacks'],1)?>
</td>
<td>
<div class="claim-bar">
<i style="width:<?=$percent?>%">
</i>
</div>
<small>
<?=number_format($claimed,3)?> / <?=number_format($boxes,3)?> 盒</small>
</td>
<td>
<?php if($canClaim&&$remainingBoxes>0):?>
<button type="button" data-claim="<?=(int)$m['id']?>" data-remaining="<?=h((string)$remainingBoxes)?>">认领</button>
<?php else:?>
<span class="material-done">
<?=!$user?'验证后操作':(!$canClaim?'仅成员可认领':'已认领完')?>
</span>
<?php endif;?>
</td>
</tr>
<?php if($materialClaims):?>
<tr class="claim-list-row" data-name="<?=h(strtolower($m['display_name'].' '.$m['block_id']))?>">
<td colspan="6">
<div class="claim-list">
<?php foreach($materialClaims as$c):?>
<span class="claim-item <?=$c['collected_at']?'collected':''?>" <?=$user&&($c['claimant']===$user['username']||$canManage)?'data-claim-select-row':''?>>
<?php if($user&&($c['claimant']===$user['username']||$canManage)):?><input type="checkbox" data-batch-claim-record data-collected="<?=$c['collected_at']?'1':'0'?>" value="<?=(int)$c['id']?>" aria-label="选择 <?=h($c['claimant'])?> 的认领记录"><?php endif;?>
<b>
<?=h($c['claimant'])?>
</b> <?=number_format((float)$c['boxes'],3)?> 盒 · <?=$c['collected_at']?'已收集':'收集中'?>
<?php if($user&&($c['claimant']===$user['username']||$canManage)):?>
<button data-toggle-claim="<?=(int)$c['id']?>">
<?=$c['collected_at']?'撤销完成':'标记完成'?>
</button>
<?php if(!$c['collected_at']):?><button data-delete-claim="<?=(int)$c['id']?>">取消</button><?php endif;?>
<?php endif;?>
</span>
<?php endforeach;?>
</div>
</td>
</tr>
<?php endif;?>
</tbody>
<?php endforeach;?>
</table>
</div>
</article>
<?php endforeach;?>
</section>
</section>
<?php if($canManage):?>
<section class="plan-create" data-upload hidden>
<div class="plan-create-panel">
<button class="plan-close" data-upload-close>×</button>
<p class="plans-kicker">IMPORT</p>
<h2>上传 Litematica 投影</h2>
<form data-upload-form enctype="multipart/form-data">
<input type="hidden" name="action" value="upload_litematic">
<input type="hidden" name="project_id" value="<?=h($id)?>">
<input type="hidden" name="csrf_token" value="<?=h($csrf)?>">
<label>投影文件<input type="file" name="litematic" accept=".litematic" required>
</label>
<p>文件只在服务器内存中解析，不公开保存；最大 100MB，解压后最大 128MB。</p>
<p class="plan-form-error" data-upload-error>
</p>
<button class="plans-primary" type="submit">解析并导入</button>
</form>
</div>
</section>
<section class="plan-create" data-edit hidden>
<div class="plan-create-panel">
<button class="plan-close" data-edit-close>×</button>
<h2>项目设置</h2>
<form data-edit-form>
<label>名称<input name="name" maxlength="80" required value="<?=h($project['name'])?>">
</label>
<label>说明<input name="description" maxlength="240" value="<?=h($project['description'])?>">
</label>
<label>详细说明<textarea name="about" maxlength="10000">
<?=h($project['about'])?>
</textarea>
</label>
<label>状态<select name="status">
<option value="active" <?=$project['status']==='active'?'selected':''?>>进行中</option>
<option value="paused" <?=$project['status']==='paused'?'selected':''?>>暂停</option>
<option value="completed" <?=$project['status']==='completed'?'selected':''?>>完成</option>
</select>
</label>
<p class="plan-form-error" data-edit-error>
</p>
<button class="plans-primary">保存</button>
<?php if($canDelete):?><button type="button" class="plan-danger" data-delete-project>删除整个项目</button><?php endif;?>
</form>
</div>
</section>
<?php endif;?>
<?php if($user):?><section class="plan-create" data-claim-modal hidden><div class="plan-create-panel plan-claim-panel" role="dialog" aria-modal="true"><button class="plan-close" type="button" data-claim-close>×</button><p class="plans-kicker">CLAIM MATERIAL</p><h2>认领材料</h2><p data-claim-description></p><form data-claim-form><input type="hidden" name="material_id"><label>认领盒数<input type="number" name="boxes" min="0.001" step="0.001" required></label><p class="plan-form-error" data-claim-error></p><div class="plan-modal-actions"><button type="button" data-claim-cancel>取消</button><button class="plans-primary" type="submit">确认认领</button></div></form></div></section><?php endif;?>
<section class="plan-create" data-confirm-modal hidden><div class="plan-create-panel plan-confirm-panel" role="dialog" aria-modal="true"><p class="plans-kicker">CONFIRM</p><h2>确认操作</h2><p data-confirm-message></p><div class="plan-modal-actions"><button type="button" data-confirm-cancel>取消</button><button type="button" class="plan-danger" data-confirm-ok>确认</button></div></div></section><div class="plan-toast" data-toast hidden></div>
</main>
<footer class="site-footer">
<div class="shell">
<span>示例服务器 · <a href="about.php">关于计划表</a></span>
<div class="filing"></div>
</div>
</footer>
<script>(function(){
const project='<?=h($id)?>',csrf='<?=h($csrf)?>',topData=<?=json_encode($topChart,JSON_UNESCAPED_UNICODE)?>,dimensionData=<?=json_encode($dimension,JSON_UNESCAPED_UNICODE)?>;
const toastBox=document.querySelector('[data-toast]');function toast(message,type='error'){toastBox.textContent=message;toastBox.className='plan-toast '+type;toastBox.hidden=false;clearTimeout(toastBox.timer);toastBox.timer=setTimeout(()=>toastBox.hidden=true,2600)}
const viewButtons=[...document.querySelectorAll('[data-view-button]')],viewPanels=[...document.querySelectorAll('[data-view-panel]')];function showView(name,updateHash=true){if(!viewButtons.some(b=>b.dataset.viewButton===name))name='overview';viewButtons.forEach(b=>b.classList.toggle('active',b.dataset.viewButton===name));viewPanels.forEach(p=>p.hidden=p.dataset.viewPanel!==name);if(updateHash)history.replaceState(history.state,'','#'+name);if(name==='materials')renderMaterials();if(name==='stats')requestAnimationFrame(()=>window.dispatchEvent(new Event('resize')))}function reloadView(name){location.hash=name;location.reload()}viewButtons.forEach(b=>b.onclick=()=>showView(b.dataset.viewButton));setTimeout(()=>showView((location.hash||'#overview').slice(1),false),0);
document.querySelector('[data-share]').onclick=async()=>{try{await navigator.clipboard.writeText(location.href);toast('项目链接已复制','success')}catch(e){toast('浏览器未允许复制，请从地址栏复制链接')}};
const confirmModal=document.querySelector('[data-confirm-modal]');let confirmResolve=null;function ask(message){confirmModal.querySelector('[data-confirm-message]').textContent=message;confirmModal.hidden=false;return new Promise(resolve=>confirmResolve=resolve)}confirmModal.querySelector('[data-confirm-cancel]').onclick=()=>{confirmModal.hidden=true;confirmResolve?.(false)};confirmModal.querySelector('[data-confirm-ok]').onclick=()=>{confirmModal.hidden=true;confirmResolve?.(true)};
async function post(body){let options={method:'POST'};if(body instanceof FormData)options.body=body;else{options.headers={'Content-Type':'application/json'};options.body=JSON.stringify({...body,project_id:project,csrf_token:csrf})}let r=await fetch('api.php',options),v=await r.json();if(!r.ok)throw new Error(v.error||'操作失败');return v}
if(window.Chart&&topData.length){new Chart(document.getElementById('material-top-chart'),{type:'bar',data:{labels:topData.map(x=>x.name),datasets:[{data:topData.map(x=>x.count),backgroundColor:'#f9a8d4'}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{color:'#98a196'},grid:{color:'#30362f'}},y:{ticks:{color:'#edf1eb'},grid:{display:false}}}}});let entries=Object.entries(dimensionData).filter(x=>x[1]>0);new Chart(document.getElementById('material-dimension-chart'),{type:'doughnut',data:{labels:entries.map(x=>x[0]),datasets:[{data:entries.map(x=>x[1]),backgroundColor:['#7fa67a','#d07161','#a49acb'],borderColor:'#151815'}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'#edf1eb'}}}}})}
 const materialSearch=document.querySelector('[data-global-material-search]'),litPanels=[...document.querySelectorAll('[data-lit-panel]')];let materialQuery='',claimFilter='all',categoryFilter='all',activeLit=litPanels[0]?.dataset.litematic||'';
 const materialChecks=[...document.querySelectorAll('[data-batch-material]')],claimChecks=[...document.querySelectorAll('[data-batch-claim-record]')],selectButtons=[...document.querySelectorAll('[data-select-page]')],clearButtons=[...document.querySelectorAll('[data-clear-selection]')],batchClaimButtons=[...document.querySelectorAll('[data-batch-claim]')],batchClaimCompleteButtons=[...document.querySelectorAll('[data-batch-claim-complete]')],batchCompleteButtons=[...document.querySelectorAll('[data-batch-complete]')],batchUncompleteButtons=[...document.querySelectorAll('[data-batch-uncomplete]')],batchDeleteButtons=[...document.querySelectorAll('[data-batch-delete-claims]')],batchSummaries=[...document.querySelectorAll('[data-batch-summary]')];
 function visibleMaterialChecks(panel){return materialChecks.filter(check=>panel.contains(check)&&!check.closest('[data-material-item]').hidden)}
 function updateBatch(){let selectedMaterials=materialChecks.filter(x=>x.checked),selectedClaims=claimChecks.filter(x=>x.checked),pendingClaims=selectedClaims.filter(x=>x.dataset.collected==='0'),completedClaims=selectedClaims.filter(x=>x.dataset.collected==='1'),claimNames={all:'全部状态',unclaimed:'未认领',claimed:'已认领'},categoryNames={all:'全部类别',building:'建筑方块',color:'彩色方块',natural:'自然方块',utility:'功能方块',redstone:'红石',other:'其他'};materialChecks.forEach(x=>x.closest('[data-material-item]')?.classList.toggle('selected',x.checked));claimChecks.forEach(x=>x.closest('[data-claim-select-row]')?.classList.toggle('selected',x.checked));batchClaimButtons.concat(batchClaimCompleteButtons).forEach(x=>x.disabled=selectedMaterials.length===0);batchCompleteButtons.forEach(x=>x.disabled=pendingClaims.length===0);batchUncompleteButtons.concat(batchDeleteButtons).forEach(x=>x.disabled=pendingClaims.length===0);batchUncompleteButtons.forEach(x=>x.disabled=completedClaims.length===0);clearButtons.forEach(x=>x.disabled=selectedMaterials.length+selectedClaims.length===0);selectButtons.forEach(button=>{let visible=visibleMaterialChecks(button.closest('[data-lit-panel]')),allSelected=visible.length>0&&visible.every(x=>x.checked);button.disabled=visible.length===0;button.textContent=allSelected?'取消全选':'全选当前筛选'});batchSummaries.forEach(summary=>{let panel=summary.closest('[data-lit-panel]'),visible=visibleMaterialChecks(panel).length,matched=panel.querySelectorAll('[data-material-item]:not([hidden])').length,scope=(claimNames[claimFilter]||claimFilter)+' · '+(categoryNames[categoryFilter]||categoryFilter)+(materialQuery?' · “'+materialQuery+'”':'');summary.textContent='筛选：'+scope+' · '+matched+' 项（可认领 '+visible+'）· 已选 '+selectedMaterials.length+' 项材料 / '+selectedClaims.length+' 条认领'})}
 function renderMaterials(){litPanels.forEach(panel=>{let active=panel.dataset.litematic===activeLit;panel.hidden=!active;if(!active)return;panel.querySelectorAll('[data-material-item]').forEach(item=>item.hidden=!((!materialQuery||item.dataset.name.includes(materialQuery))&&(claimFilter==='all'||item.dataset.claimState===claimFilter)&&(categoryFilter==='all'||item.dataset.category===categoryFilter)))});updateBatch()}
 document.querySelectorAll('[data-lit-tab]').forEach(b=>b.onclick=()=>{activeLit=b.dataset.litTab;document.querySelectorAll('[data-lit-tab]').forEach(x=>x.classList.toggle('active',x===b));renderMaterials()});if(materialSearch)materialSearch.oninput=()=>{materialQuery=materialSearch.value.trim().toLowerCase();renderMaterials()};document.querySelectorAll('[data-claim-filter] button').forEach(b=>b.onclick=()=>{claimFilter=b.dataset.value;b.parentElement.querySelectorAll('button').forEach(x=>x.classList.toggle('active',x===b));renderMaterials()});document.querySelectorAll('[data-category-filter] button').forEach(b=>b.onclick=()=>{categoryFilter=b.dataset.value;b.parentElement.querySelectorAll('button').forEach(x=>x.classList.toggle('active',x===b));renderMaterials()});
 materialChecks.concat(claimChecks).forEach(x=>x.onchange=updateBatch);document.querySelectorAll('[data-material-select-row],[data-claim-select-row]').forEach(row=>row.onclick=e=>{if(e.target.closest('button,a,input,label,select,textarea'))return;let check=row.querySelector('input[type=checkbox]');if(!check)return;check.checked=!check.checked;updateBatch()});selectButtons.forEach(button=>button.onclick=()=>{let visible=visibleMaterialChecks(button.closest('[data-lit-panel]')),select=visible.some(x=>!x.checked);visible.forEach(x=>x.checked=select);updateBatch()});clearButtons.forEach(button=>button.onclick=()=>{materialChecks.concat(claimChecks).forEach(x=>x.checked=false);updateBatch()});
 async function batchClaim(collected){let ids=materialChecks.filter(x=>x.checked).map(x=>Number(x.value));if(!ids.length)return;let message=collected?'认领所选 '+ids.length+' 项材料的全部剩余数量，并直接标记为完成？':'认领所选 '+ids.length+' 项材料的全部剩余数量？';if(!await ask(message))return;batchClaimButtons.concat(batchClaimCompleteButtons).forEach(x=>x.disabled=true);try{await post({action:'batch_claim',material_ids:ids,collected:collected});reloadView('materials')}catch(e){toast(e.message);updateBatch()}}
 async function batchCollected(collected){let ids=claimChecks.filter(x=>x.checked&&x.dataset.collected===(collected?'0':'1')).map(x=>Number(x.value));if(!ids.length)return;if(!await ask((collected?'将所选 ':'撤销所选 ')+ids.length+' 条认领记录的完成状态？'))return;batchCompleteButtons.concat(batchUncompleteButtons).forEach(x=>x.disabled=true);try{await post({action:'batch_set_collected',claim_ids:ids,collected:collected});reloadView('materials')}catch(e){toast(e.message);updateBatch()}}
 async function batchDelete(){let ids=claimChecks.filter(x=>x.checked&&x.dataset.collected==='0').map(x=>Number(x.value));if(!ids.length)return;if(!await ask('取消所选 '+ids.length+' 条认领记录？'))return;batchDeleteButtons.forEach(x=>x.disabled=true);try{await post({action:'batch_delete_claims',claim_ids:ids});reloadView('materials')}catch(e){toast(e.message);updateBatch()}}
 batchClaimButtons.forEach(button=>button.onclick=()=>batchClaim(false));batchClaimCompleteButtons.forEach(button=>button.onclick=()=>batchClaim(true));batchCompleteButtons.forEach(button=>button.onclick=()=>batchCollected(true));batchUncompleteButtons.forEach(button=>button.onclick=()=>batchCollected(false));batchDeleteButtons.forEach(button=>button.onclick=batchDelete);updateBatch();
 const claimModal=document.querySelector('[data-claim-modal]'),claimForm=document.querySelector('[data-claim-form]');if(claimModal){let closeClaim=()=>claimModal.hidden=true;document.querySelector('[data-claim-close]').onclick=closeClaim;document.querySelector('[data-claim-cancel]').onclick=closeClaim;document.querySelectorAll('[data-claim]').forEach(b=>b.onclick=()=>{let row=b.closest('tr'),name=row.querySelector('.material-name strong')?.textContent?.trim()||'该材料',remaining=Number(b.dataset.remaining);claimForm.material_id.value=b.dataset.claim;claimForm.boxes.value=Math.min(1,remaining).toFixed(3);claimForm.boxes.max=remaining;document.querySelector('[data-claim-description]').textContent=name+'，剩余 '+remaining.toFixed(3)+' 盒';document.querySelector('[data-claim-error]').textContent='';claimModal.hidden=false});claimForm.onsubmit=async e=>{e.preventDefault();let button=e.target.querySelector('[type=submit]');button.disabled=true;try{await post({action:'claim',material_id:e.target.material_id.value,boxes:Number(e.target.boxes.value)});location.reload()}catch(x){document.querySelector('[data-claim-error]').textContent=x.message;button.disabled=false}}}
document.querySelectorAll('[data-toggle-claim]').forEach(b=>b.onclick=async()=>{try{await post({action:'toggle_collected',claim_id:b.dataset.toggleClaim});location.reload()}catch(e){toast(e.message)}});document.querySelectorAll('[data-delete-claim]').forEach(b=>b.onclick=async()=>{if(await ask('取消这条材料认领记录？'))try{await post({action:'delete_claim',claim_id:b.dataset.deleteClaim});location.reload()}catch(e){toast(e.message)}});
const memberForm=document.querySelector('[data-member-form]');if(memberForm)memberForm.onsubmit=async e=>{e.preventDefault();try{await post({action:'add_member',...Object.fromEntries(new FormData(e.target))});reloadView('members')}catch(x){toast(x.message)}};document.querySelectorAll('[data-remove-member]').forEach(b=>b.onclick=async()=>{if(await ask('从项目中移除成员 '+b.dataset.removeMember+'？'))try{await post({action:'remove_member',username:b.dataset.removeMember});reloadView('members')}catch(x){toast(x.message)}});
document.querySelectorAll('[data-delete-litematic]').forEach(b=>b.onclick=async()=>{if(await ask('删除这个投影及其全部材料认领记录？'))try{await post({action:'delete_litematic',litematic_id:b.dataset.deleteLitematic});location.reload()}catch(e){toast(e.message)}});
const upload=document.querySelector('[data-upload]');if(upload){document.querySelector('[data-upload-open]').onclick=()=>upload.hidden=false;document.querySelector('[data-upload-close]').onclick=()=>upload.hidden=true;document.querySelector('[data-upload-form]').onsubmit=async e=>{e.preventDefault();let button=e.target.querySelector('[type=submit]'),error=document.querySelector('[data-upload-error]');button.disabled=true;button.textContent='正在解析';try{await post(new FormData(e.target));location.reload()}catch(x){error.textContent=x.message;button.disabled=false;button.textContent='解析并导入'}}}
const edit=document.querySelector('[data-edit]');if(edit){document.querySelectorAll('[data-edit-open]').forEach(b=>b.onclick=()=>edit.hidden=false);document.querySelector('[data-edit-close]').onclick=()=>edit.hidden=true;document.querySelector('[data-edit-form]').onsubmit=async e=>{e.preventDefault();try{await post({action:'update_project',...Object.fromEntries(new FormData(e.target))});location.reload()}catch(x){document.querySelector('[data-edit-error]').textContent=x.message}};const deleteProject=document.querySelector('[data-delete-project]');if(deleteProject)deleteProject.onclick=async()=>{if(await ask('永久删除整个项目、全部投影和认领记录？'))try{await post({action:'delete_project'});location.href='./'}catch(e){toast(e.message)}
};}
})();</script>
</body>
</html>
