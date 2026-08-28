<?php
declare(strict_types=1);
require __DIR__.'/db.php';
require __DIR__.'/litematic.php';
require_once __DIR__.'/../统一认证/auth.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
function out(array $v,int $s=200):never{http_response_code($s);echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function fail(Throwable $e,int $s=500):never{error_log('Plan API: '.$e->getMessage());out(['error'=>$s===500?'server_error':$e->getMessage()],$s);}
function plan_input():array{$type=$_SERVER['CONTENT_TYPE']??'';if(str_contains($type,'application/json')){$v=json_decode((string)file_get_contents('php://input'),true);return is_array($v)?$v:[];}return $_POST;}
function plan_require_user(array $input):array{if(!auth_is_authenticated())out(['error'=>'authentication_required'],401);try{auth_verify_csrf((string)($input['csrf_token']??''));}catch(Throwable $e){out(['error'=>'csrf_failed'],403);}return auth_current_user();}
function plan_can_manage(array $project,array $user):bool{if($project['owner_username']===$user['username']||auth_is_site_admin())return true;foreach((array)($project['members']??[]) as $member)if($member['username']===$user['username']&&$member['role']==='admin')return true;return false;}
function plan_input_ids(mixed $value,string $label):array{$ids=is_array($value)?array_values(array_unique(array_filter(array_map('intval',$value),fn($id)=>$id>0))):[];if(!$ids)out(['error'=>'请选择'.$label],422);sort($ids,SORT_NUMERIC);return $ids;}
try{$pdo=plan_db();$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){$id=trim((string)($_GET['id']??''));if($id!==''){$p=plan_project($pdo,$id);if(!$p)out(['error'=>'not_found'],404);out(['project'=>$p]);}out(['projects'=>plan_projects($pdo)]);}
 if($method!=='POST')out(['error'=>'method_not_allowed'],405);
 $input=plan_input();$user=plan_require_user($input);$action=(string)($input['action']??'create_project');
 if($action==='create_project'){
   if(!auth_is_site_admin())out(['error'=>'只有站点管理员可以创建项目'],403);
   $name=trim((string)($input['name']??''));$description=trim((string)($input['description']??''));$about=trim((string)($input['about']??''));
   if($name===''||mb_strlen($name)>80)out(['error'=>'项目名称为 1-80 个字符'],422);
   $id=bin2hex(random_bytes(12));$now=date('Y-m-d H:i:s');$stmt=$pdo->prepare('INSERT INTO plan_projects(id,name,description,about,status,owner_username,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)');
   $pdo->beginTransaction();try{$stmt->execute([$id,$name,mb_substr($description,0,240),mb_substr($about,0,10000),'active',$user['username'],$now,$now]);$pdo->prepare('INSERT INTO plan_members(project_id,username,role,joined_at) VALUES(?,?,?,?)')->execute([$id,$user['username'],'owner',$now]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}auth_audit('plan_project_created',['project_id'=>$id]);out(['id'=>$id],201);
 }
 if($action==='update_profile'){
   $nickname=trim((string)($input['nickname']??''));$minecraft=trim((string)($input['minecraft_username']??''));
   if(mb_strlen($nickname)>64)out(['error'=>'展示昵称不能超过 64 个字符'],422);
   if($minecraft!==''&&!preg_match('/^[A-Za-z0-9_]{1,16}$/',$minecraft))out(['error'=>'Minecraft 名称格式不正确'],422);
   $pdo->prepare('INSERT INTO plan_profiles(username,nickname,minecraft_username,updated_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nickname=VALUES(nickname),minecraft_username=VALUES(minecraft_username),updated_at=VALUES(updated_at)')->execute([$user['username'],$nickname,$minecraft,date('Y-m-d H:i:s')]);auth_audit('plan_profile_updated');out(['ok'=>true]);
 }
 $projectId=trim((string)($input['project_id']??''));$project=plan_project($pdo,$projectId);if(!$project)out(['error'=>'project_not_found'],404);
 if($action==='add_member'||$action==='remove_member'){
   $target=trim((string)($input['username']??''));if($target===''||$target===$project['owner_username'])out(['error'=>'成员无效或不能操作创建者'],422);
   if($action==='remove_member'){if($target!==$user['username']&&!plan_can_manage($project,$user))out(['error'=>'forbidden'],403);$pdo->prepare('DELETE FROM plan_members WHERE project_id=? AND username=?')->execute([$projectId,$target]);auth_audit('plan_member_removed',['project_id'=>$projectId,'username'=>$target]);out(['ok'=>true]);}
   if(!plan_can_manage($project,$user))out(['error'=>'forbidden'],403);
   $check=auth_db()->prepare('SELECT username FROM users WHERE username=? AND enabled=1');$check->execute([$target]);if(!$check->fetch())out(['error'=>'统一认证成员不存在或已停用'],422);$role=in_array($input['role']??'', ['admin','member'],true)?$input['role']:'member';$pdo->prepare('INSERT INTO plan_members(project_id,username,role,joined_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE role=VALUES(role)')->execute([$projectId,$target,$role,date('Y-m-d H:i:s')]);auth_audit('plan_member_added',['project_id'=>$projectId,'username'=>$target,'role'=>$role]);out(['ok'=>true]);
 }
 if($action==='upload_litematic'){
   if(!plan_can_manage($project,$user))out(['error'=>'forbidden'],403);$file=$_FILES['litematic']??null;
   if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)out(['error'=>'请选择有效的 .litematic 文件'],422);
   if((int)$file['size']>100*1024*1024)out(['error'=>'投影文件不能超过 100MB'],413);
   $filename=basename((string)$file['name']);if(strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='litematic')out(['error'=>'只允许上传 .litematic 文件'],422);
   @set_time_limit(60);try{$materials=plan_parse_litematic((string)$file['tmp_name']);}catch(RuntimeException $e){out(['error'=>$e->getMessage()],422);}if(!$materials)out(['error'=>'投影中没有可统计的方块'],422);
   $id=bin2hex(random_bytes(12));$now=date('Y-m-d H:i:s');$total=array_sum(array_column($materials,'count'));
   $pdo->beginTransaction();try{$stmt=$pdo->prepare('INSERT INTO plan_litematics(id,project_id,filename,total_types,total_blocks,created_at) VALUES(?,?,?,?,?,?)');$stmt->execute([$id,$projectId,mb_substr($filename,0,180),count($materials),$total,$now]);$insert=$pdo->prepare('INSERT INTO plan_materials(litematic_id,block_id,display_name,block_count,boxes,stacks) VALUES(?,?,?,?,?,?)');foreach($materials as $m)$insert->execute([$id,$m['blockId'],$m['displayName'],$m['count'],$m['boxes'],$m['stacks']]);$pdo->prepare('UPDATE plan_projects SET updated_at=? WHERE id=?')->execute([$now,$projectId]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
   auth_audit('plan_litematic_uploaded',['project_id'=>$projectId,'filename'=>$filename,'blocks'=>$total]);out(['litematic_id'=>$id,'types'=>count($materials),'blocks'=>$total],201);
 }
 if($action==='claim'){
   $isMember=(bool)array_filter($project['members'],fn($m)=>$m['username']===$user['username']);if(!$isMember&&!auth_is_site_admin())out(['error'=>'只有项目成员可以认领'],403);
   $materialId=(int)($input['material_id']??0);$boxes=round((float)($input['boxes']??0),3);if($boxes<=0)out(['error'=>'认领数量必须大于 0'],422);
   $pdo->beginTransaction();try{
    $stmt=$pdo->prepare('SELECT m.*,l.project_id FROM plan_materials m JOIN plan_litematics l ON l.id=m.litematic_id WHERE m.id=? AND l.project_id=? FOR UPDATE');$stmt->execute([$materialId,$projectId]);$m=$stmt->fetch();if(!$m){$pdo->rollBack();out(['error'=>'material_not_found'],404);}
    $sum=$pdo->prepare('SELECT COALESCE(SUM(boxes),0) FROM plan_claims WHERE material_id=?');$sum->execute([$materialId]);$remaining=max(0,(float)$m['boxes']-(float)$sum->fetchColumn());if($boxes-$remaining>0.0000001){$pdo->rollBack();out(['error'=>'认领数量超过剩余材料'],422);}
    $stmt=$pdo->prepare('INSERT INTO plan_claims(project_id,litematic_id,material_id,claimant,boxes,collected_at,created_at) VALUES(?,?,?,?,?,NULL,?)');$stmt->execute([$projectId,$m['litematic_id'],$materialId,$user['username'],$boxes,date('Y-m-d H:i:s')]);$claimId=(int)$pdo->lastInsertId();$pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   auth_audit('plan_claim_created',['project_id'=>$projectId,'material_id'=>$materialId,'boxes'=>$boxes]);out(['id'=>$claimId],201);
 }
 if($action==='batch_claim'){
   $isMember=(bool)array_filter($project['members'],fn($m)=>$m['username']===$user['username']);if(!$isMember&&!auth_is_site_admin())out(['error'=>'只有项目成员可以认领'],403);
   $materialIds=plan_input_ids($input['material_ids']??null,'材料');$placeholders=implode(',',array_fill(0,count($materialIds),'?'));$params=array_merge([$projectId],$materialIds);$now=date('Y-m-d H:i:s');$collectedAt=!empty($input['collected'])?$now:null;$created=[];
   $pdo->beginTransaction();try{
    $stmt=$pdo->prepare("SELECT m.* FROM plan_materials m JOIN plan_litematics l ON l.id=m.litematic_id WHERE l.project_id=? AND m.id IN ($placeholders) ORDER BY m.id FOR UPDATE");$stmt->execute($params);$materials=$stmt->fetchAll();
    if(count($materials)!==count($materialIds)){$pdo->rollBack();out(['error'=>'所选材料不存在或不属于当前项目'],422);}
    $sum=$pdo->prepare("SELECT material_id,COALESCE(SUM(boxes),0) claimed_boxes FROM plan_claims WHERE material_id IN ($placeholders) GROUP BY material_id");$sum->execute($materialIds);$claimed=array_column($sum->fetchAll(),'claimed_boxes','material_id');
    $insert=$pdo->prepare('INSERT INTO plan_claims(project_id,litematic_id,material_id,claimant,boxes,collected_at,created_at) VALUES(?,?,?,?,?,?,?)');
    foreach($materials as $material){$remaining=round(max(0,(float)$material['boxes']-(float)($claimed[$material['id']]??0)),3);if($remaining<=0)continue;$insert->execute([$projectId,$material['litematic_id'],$material['id'],$user['username'],$remaining,$collectedAt,$now]);$created[]=(int)$material['id'];}
    if(!$created){$pdo->rollBack();out(['error'=>'所选材料已全部认领'],422);}$pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   auth_audit('plan_claims_batch_created',['project_id'=>$projectId,'material_count'=>count($created),'collected'=>$collectedAt!==null]);out(['ok'=>true,'created'=>count($created),'collected'=>$collectedAt!==null],201);
 }
 if($action==='batch_set_collected'){
   $claimIds=plan_input_ids($input['claim_ids']??null,'认领记录');$placeholders=implode(',',array_fill(0,count($claimIds),'?'));$params=array_merge([$projectId],$claimIds);$value=!empty($input['collected'])?date('Y-m-d H:i:s'):null;
   $pdo->beginTransaction();try{
    $stmt=$pdo->prepare("SELECT * FROM plan_claims WHERE project_id=? AND id IN ($placeholders) ORDER BY id FOR UPDATE");$stmt->execute($params);$claims=$stmt->fetchAll();if(count($claims)!==count($claimIds)){$pdo->rollBack();out(['error'=>'所选认领记录不存在或不属于当前项目'],422);}
    foreach($claims as $claim)if($claim['claimant']!==$user['username']&&!plan_can_manage($project,$user)){$pdo->rollBack();out(['error'=>'forbidden'],403);}
    $update=$pdo->prepare("UPDATE plan_claims SET collected_at=? WHERE project_id=? AND id IN ($placeholders)");$update->execute(array_merge([$value,$projectId],$claimIds));$pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   auth_audit('plan_claims_batch_collected',['project_id'=>$projectId,'claim_count'=>count($claimIds),'collected'=>$value!==null]);out(['ok'=>true,'updated'=>count($claimIds)]);
 }
 if($action==='batch_delete_claims'){
   $claimIds=plan_input_ids($input['claim_ids']??null,'认领记录');$placeholders=implode(',',array_fill(0,count($claimIds),'?'));$params=array_merge([$projectId],$claimIds);
   $pdo->beginTransaction();try{
    $stmt=$pdo->prepare("SELECT * FROM plan_claims WHERE project_id=? AND id IN ($placeholders) ORDER BY id FOR UPDATE");$stmt->execute($params);$claims=$stmt->fetchAll();if(count($claims)!==count($claimIds)){$pdo->rollBack();out(['error'=>'所选认领记录不存在或不属于当前项目'],422);}
    foreach($claims as $claim){if($claim['claimant']!==$user['username']&&!plan_can_manage($project,$user)){$pdo->rollBack();out(['error'=>'forbidden'],403);}if($claim['collected_at']){$pdo->rollBack();out(['error'=>'已完成的认领不能取消，请先撤销完成状态'],422);}}
    $delete=$pdo->prepare("DELETE FROM plan_claims WHERE project_id=? AND id IN ($placeholders)");$delete->execute($params);$pdo->commit();
   }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
   auth_audit('plan_claims_batch_deleted',['project_id'=>$projectId,'claim_count'=>count($claimIds)]);out(['ok'=>true,'deleted'=>count($claimIds)]);
 }
 if($action==='toggle_collected'||$action==='delete_claim'){
   $claimId=(int)($input['claim_id']??0);$stmt=$pdo->prepare('SELECT * FROM plan_claims WHERE id=? AND project_id=?');$stmt->execute([$claimId,$projectId]);$claim=$stmt->fetch();if(!$claim)out(['error'=>'claim_not_found'],404);if($claim['claimant']!==$user['username']&&!plan_can_manage($project,$user))out(['error'=>'forbidden'],403);
   if($action==='delete_claim'){if($claim['collected_at'])out(['error'=>'已完成的认领不能取消，请先撤销完成状态'],422);$pdo->prepare('DELETE FROM plan_claims WHERE id=?')->execute([$claimId]);auth_audit('plan_claim_deleted',['claim_id'=>$claimId]);}
   else{$value=$claim['collected_at']?null:date('Y-m-d H:i:s');$pdo->prepare('UPDATE plan_claims SET collected_at=? WHERE id=?')->execute([$value,$claimId]);auth_audit('plan_claim_toggled',['claim_id'=>$claimId]);}out(['ok'=>true]);
 }
 if($action==='delete_litematic'||$action==='delete_project'||$action==='update_project'){
   if(!plan_can_manage($project,$user))out(['error'=>'forbidden'],403);
   if($action==='delete_litematic'){$id=(string)($input['litematic_id']??'');$pdo->prepare('DELETE FROM plan_litematics WHERE id=? AND project_id=?')->execute([$id,$projectId]);auth_audit('plan_litematic_deleted',['project_id'=>$projectId,'litematic_id'=>$id]);out(['ok'=>true]);}
   if($action==='delete_project'){if($project['owner_username']!==$user['username']&&!auth_is_site_admin())out(['error'=>'只有项目创建者可以删除整个项目'],403);$pdo->prepare('DELETE FROM plan_projects WHERE id=?')->execute([$projectId]);auth_audit('plan_project_deleted',['project_id'=>$projectId]);out(['ok'=>true]);}
   $status=in_array($input['status']??'',['active','paused','completed'],true)?$input['status']:'active';$name=trim((string)($input['name']??''));if($name==='')out(['error'=>'项目名称不能为空'],422);$pdo->prepare('UPDATE plan_projects SET name=?,description=?,about=?,status=?,updated_at=? WHERE id=?')->execute([mb_substr($name,0,80),mb_substr((string)($input['description']??''),0,240),mb_substr((string)($input['about']??''),0,10000),$status,date('Y-m-d H:i:s'),$projectId]);auth_audit('plan_project_updated',['project_id'=>$projectId]);out(['ok'=>true]);
 }
 out(['error'=>'unknown_action'],400);
}catch(RuntimeException $e){fail($e,422);}catch(Throwable $e){fail($e);}
