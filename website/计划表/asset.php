<?php
declare(strict_types=1);
header('Cache-Control: public, max-age=86400, stale-while-revalidate=604800');
$kind=(string)($_GET['kind']??'');$value=(string)($_GET['id']??$_GET['name']??'');
$private=PHP_OS_FAMILY==='Windows'?__DIR__.'/../data/plan-assets':__DIR__.'/../../private-data/plan-assets';
if(!is_dir($private))@mkdir($private,0770,true);
function fetch_asset(array $urls):?string{foreach($urls as$url){$ctx=stream_context_create(['http'=>['timeout'=>4,'user_agent'=>'MC-Plan-Assets/1.0','ignore_errors'=>true],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);$data=@file_get_contents($url,false,$ctx);if(is_string($data)&&strlen($data)>50&&strlen($data)<1024*1024)return $data;}return null;}
function fallback_avatar(string $name):never{$letter=htmlspecialchars(strtoupper(substr($name?:'?',0,1)),ENT_QUOTES,'UTF-8');$hue=abs(crc32($name))%360;header('Content-Type: image/svg+xml; charset=utf-8');echo '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><rect width="64" height="64" fill="hsl('.$hue.',45%,38%)"/><text x="32" y="34" dominant-baseline="middle" text-anchor="middle" font-family="sans-serif" font-weight="700" font-size="30" fill="white">'.$letter.'</text></svg>';exit;}
function fallback_block():never{header('Content-Type: image/png');echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');exit;}
if($kind==='avatar'){
 if(!preg_match('/^[A-Za-z0-9_]{1,16}$/',$value))fallback_avatar($value);$cache=$private.'/avatar-'.strtolower($value).'.png';
 $data=null;if(is_file($cache)&&filemtime($cache)>time()-86400)$data=file_get_contents($cache);else{$data=fetch_asset(['https://mc-heads.net/avatar/'.rawurlencode($value).'/64','https://crafthead.net/avatar/'.rawurlencode($value).'/64']);if($data)@file_put_contents($cache,$data,LOCK_EX);}
 if(!$data)fallback_avatar($value);header('Content-Type: image/png');echo $data;exit;
}
if($kind==='block'){
 $id=str_replace('minecraft:','',$value);if(!preg_match('/^[a-z0-9_]{1,120}$/',$id))fallback_block();
 $aliases=['water'=>'water_bucket','lava'=>'lava_bucket','wall_torch'=>'torch','soul_wall_torch'=>'soul_torch','redstone_wall_torch'=>'redstone_torch','moving_piston'=>'piston','piston_head'=>'piston','cave_vines'=>'glow_berries','cave_vines_plant'=>'glow_berries','tripwire'=>'string','powder_snow'=>'powder_snow_bucket'];$id=$aliases[$id]??preg_replace('/^potted_/','',$id);
 $cache=$private.'/block-'.$id.'.png';$data=null;if(is_file($cache))$data=file_get_contents($cache);else{$base='https://assets.mcasset.cloud/1.21.5/assets/minecraft/textures/';$data=fetch_asset([$base.'block/'.$id.'.png',$base.'item/'.$id.'.png']);if($data)@file_put_contents($cache,$data,LOCK_EX);}
 if(!$data)fallback_block();header('Content-Type: image/png');echo $data;exit;
}
http_response_code(404);
