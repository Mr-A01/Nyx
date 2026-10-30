<?php
declare(strict_types=1);
namespace Nyx\Modules\Gateway;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\IdGenerator\UlidLikeId;
use Nyx\Shared\Infrastructure\Storage\FileStore;

/**
 * Modular API gateway — domain handlers grouped; no legacy relay.
 */
final class ApiGateway {
    private function store(): FileStore { return new FileStore(defined('DATA_DIR')?DATA_DIR:''); }

    public function root(Request $r): JsonResponse {
        return ApiEnvelope::ok([
            'service'=>'nyx-relay','version'=>defined('NYX_VERSION')?NYX_VERSION:'1.43.0-modular',
            'protocol'=>3,'host'=>$_SERVER['HTTP_HOST']??'localhost','time_ms'=>ApiEnvelope::nowMs(),
            'sodium'=>extension_loaded('sodium'),'sqlite'=>extension_loaded('pdo_sqlite'),
            'status'=>'ok','client'=>'client.html','e2ee'=>true,'architecture'=>'app-modules',
        ]);
    }
    public function info(Request $r): JsonResponse {
        return ApiEnvelope::ok([
            'service'=>'nyx-relay','version'=>defined('NYX_VERSION')?NYX_VERSION:'1.43.0-modular','protocol'=>3,
            'features'=>['auth','devices','prekeys','messages','edit','delete','reactions','pins','spaces','federation','presence','e2ee','attachments','identity','ops','modular','crypto','push','moderation'],
            'architecture'=>'app-modules',
            'modules'=>['Messaging','Identity','Devices','Crypto','Federation','Presence','Notifications','Attachments','Moderation','Conversations','Administration'],
        ]);
    }
    public function selftest(Request $r): JsonResponse {
        $d=defined('DATA_DIR')?DATA_DIR:''; $w=$d!==''&&(is_writable($d)||@mkdir($d,0750,true));
        $peers=0; $t=$this->store()->get('topology/nodes.json'); if(is_array($t['nodes']??null))$peers=count($t['nodes']);
        return ApiEnvelope::ok(['ok'=>extension_loaded('sodium')&&$w,'checks'=>[
            'php'=>PHP_VERSION,'sodium'=>extension_loaded('sodium'),'sqlite'=>extension_loaded('pdo_sqlite'),
            'data_writable'=>$w,'version'=>defined('NYX_VERSION')?NYX_VERSION:'1.43.0-modular','peers_configured'=>$peers,
        ]]);
    }
    public function metrics(Request $r): JsonResponse {
        return ApiEnvelope::ok(['time_ms'=>ApiEnvelope::nowMs(),'memory_bytes'=>memory_get_usage(true)]);
    }
    public function network(Request $r): JsonResponse {
        $topo=$this->store()->get('topology/nodes.json')??['nodes'=>[]]; $results=[];
        foreach(($topo['nodes']??[]) as $n){
            if(!is_array($n)||empty($n['url']))continue;
            $ok=false;$t0=microtime(true);
            if(function_exists('curl_init')){
                $ch=curl_init(rtrim($n['url'],'/').'/');
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3,CURLOPT_CONNECTTIMEOUT=>2]);
                curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
                $ok=$code>=200&&$code<500;
            }
            $results[]=['url'=>$n['url'],'ok'=>$ok,'latency_ms'=>(int)round((microtime(true)-$t0)*1000)];
        }
        return ApiEnvelope::ok(['peers'=>$results,'count'=>count($results),'time_ms'=>ApiEnvelope::nowMs()]);
    }

    // Messaging
    public function msgSend(Request $r): JsonResponse {
        $b=$r->json()??[]; $cid=(string)($b['conv_id']??''); $text=(string)($b['body']??$b['ciphertext']??'');
        if($cid===''||$text==='') return ApiEnvelope::error('conv_id and body required',422);
        $id=(string)($b['id']??UlidLikeId::generate()); $now=ApiEnvelope::nowMs();
        $rec=['id'=>$id,'conv_id'=>$cid,'sender_id'=>(string)($b['sender_id']??''),'recipient_id'=>(string)($b['recipient_id']??''),
            'body'=>$text,'payload'=>$b,'created_ms'=>$now,'edited_ms'=>null,'deleted'=>0,'e2ee'=>!empty($b['ciphertext'])];
        $s=$this->store(); $s->put("messages/{$cid}/{$id}.json",$rec);
        $idx=$s->get("messages/{$cid}/_index.json")??['ids'=>[]]; $ids=$idx['ids']??[]; $ids[]=$id;
        $s->put("messages/{$cid}/_index.json",['ids'=>array_values(array_unique($ids)),'updated_ms'=>$now]);
        return ApiEnvelope::ok(['id'=>$id,'conv_id'=>$cid,'created_ms'=>$now,'stored'=>true]);
    }
    public function msgSync(Request $r): JsonResponse {
        $b=$r->json()??[]; $cid=(string)($r->query('conv_id')??$b['conv_id']??''); $since=(int)($r->query('since_ms')??$b['since_ms']??0);
        if($cid==='') return ApiEnvelope::error('conv_id required',422);
        $s=$this->store(); $idx=$s->get("messages/{$cid}/_index.json")??['ids'=>[]]; $out=[];
        foreach(($idx['ids']??[]) as $id){ $m=$s->get("messages/{$cid}/{$id}.json");
            if(!is_array($m)||!empty($m['deleted'])||(int)($m['created_ms']??0)<=$since)continue; $out[]=$m; if(count($out)>=50)break; }
        usort($out,fn($a,$b)=>($a['created_ms']??0)<=>($b['created_ms']??0));
        return ApiEnvelope::ok(['conv_id'=>$cid,'messages'=>$out,'count'=>count($out),'since_ms'=>$since]);
    }
    public function msgEdit(Request $r): JsonResponse {
        $b=$r->json()??[]; $id=(string)($b['id']??''); $cid=(string)($b['conv_id']??''); $text=(string)($b['body']??'');
        if($id===''||$cid===''||$text==='') return ApiEnvelope::error('id, conv_id, body required',422);
        $s=$this->store(); $m=$s->get("messages/{$cid}/{$id}.json"); if(!$m) return ApiEnvelope::error('not found',404);
        $m['body']=$text; $m['edited_ms']=ApiEnvelope::nowMs(); $s->put("messages/{$cid}/{$id}.json",$m);
        return ApiEnvelope::ok(['id'=>$id,'edited_ms'=>$m['edited_ms'],'body'=>$text]);
    }
    public function msgDelete(Request $r): JsonResponse {
        $b=$r->json()??[]; $id=(string)($b['id']??''); $cid=(string)($b['conv_id']??'');
        if($id===''||$cid==='') return ApiEnvelope::error('id and conv_id required',422);
        $s=$this->store(); $m=$s->get("messages/{$cid}/{$id}.json"); if(!$m) return ApiEnvelope::error('not found',404);
        $m['deleted']=1; $m['body']=''; $m['deleted_ms']=ApiEnvelope::nowMs(); $s->put("messages/{$cid}/{$id}.json",$m);
        return ApiEnvelope::ok(['id'=>$id,'deleted'=>true]);
    }
    public function msgReact(Request $r): JsonResponse {
        $b=$r->json()??[]; $mid=(string)($b['message_id']??$b['id']??''); $uid=(string)($b['user_id']??''); $emoji=(string)($b['emoji']??'');
        if($mid===''||$uid===''||$emoji==='') return ApiEnvelope::error('message_id, user_id, emoji required',422);
        $key="reactions/{$mid}/{$uid}_".md5($emoji).".json"; $s=$this->store();
        if(!empty($b['remove'])){ $s->delete($key); return ApiEnvelope::ok(['removed'=>true]); }
        $rec=['message_id'=>$mid,'user_id'=>$uid,'emoji'=>$emoji,'conv_id'=>$b['conv_id']??'','created_ms'=>ApiEnvelope::nowMs()];
        $s->put($key,$rec); return ApiEnvelope::ok($rec);
    }
    public function msgPin(Request $r): JsonResponse {
        $b=$r->json()??[]; $mid=(string)($b['message_id']??''); $cid=(string)($b['conv_id']??'');
        if($mid===''||$cid==='') return ApiEnvelope::error('message_id and conv_id required',422);
        $s=$this->store(); $pins=$s->get("pins/{$cid}.json")??['message_ids'=>[]]; $ids=$pins['message_ids']??[];
        if(!empty($b['unpin'])) $ids=array_values(array_filter($ids,fn($x)=>$x!==$mid));
        elseif(!in_array($mid,$ids,true)) $ids[]=$mid;
        $pins=['conv_id'=>$cid,'message_ids'=>$ids,'updated_ms'=>ApiEnvelope::nowMs()]; $s->put("pins/{$cid}.json",$pins);
        return ApiEnvelope::ok($pins);
    }

    // Identity
    public function challenge(Request $r): JsonResponse {
        $n=bin2hex(random_bytes(16)); $e=ApiEnvelope::nowMs()+300000;
        $this->store()->put("identity/challenges/{$n}.json",['nonce'=>$n,'expires_ms'=>$e]);
        return ApiEnvelope::ok(['nonce'=>$n,'expires_ms'=>$e,'alg'=>'ed25519']);
    }
    public function register(Request $r): JsonResponse {
        $b=$r->json()??[]; $u=strtolower(trim((string)($b['username']??''))); $pk=(string)($b['public_key']??'');
        if($u===''||!preg_match('/^[a-z0-9_]{3,32}$/',$u)) return ApiEnvelope::error('invalid username',422);
        if($pk==='') return ApiEnvelope::error('public_key required',422);
        if($this->store()->get("identity/usernames/{$u}.json")) return ApiEnvelope::error('username taken',409);
        $uid=(string)($b['user_id']??UlidLikeId::generate()); $rec=['user_id'=>$uid,'username'=>$u,'public_key'=>$pk,'created_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("identity/usernames/{$u}.json",$rec); $this->store()->put("users/{$uid}.json",$rec);
        return ApiEnvelope::ok($rec);
    }
    public function username(Request $r): JsonResponse {
        $u=strtolower(trim((string)($r->query('username')??($r->json()['username']??''))));
        if($u==='') return ApiEnvelope::error('username required',422);
        $row=$this->store()->get("identity/usernames/{$u}.json");
        return $row?ApiEnvelope::ok($row):ApiEnvelope::error('not found',404);
    }

    // Devices / Presence / Spaces
    public function deviceRegister(Request $r): JsonResponse {
        $b=$r->json()??[]; $uid=(string)($b['user_id']??''); if($uid==='') return ApiEnvelope::error('user_id required',422);
        $did=(string)($b['device_id']??UlidLikeId::generate()); $now=ApiEnvelope::nowMs();
        $rec=['device_id'=>$did,'user_id'=>$uid,'public_key'=>$b['public_key']??'','created_ms'=>$now,'touched_ms'=>$now];
        $this->store()->put("devices/{$did}.json",$rec); return ApiEnvelope::ok($rec);
    }
    public function presenceHeartbeat(Request $r): JsonResponse {
        $b=$r->json()??[]; $uid=(string)($b['user_id']??''); if($uid==='') return ApiEnvelope::error('user_id required',422);
        $rec=['user_id'=>$uid,'status'=>$b['status']??'online','last_seen_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("presence/{$uid}.json",$rec); return ApiEnvelope::ok($rec);
    }
    public function presenceBatch(Request $r): JsonResponse {
        $ids=$r->json()['user_ids']??$r->query('ids')??[]; if(is_string($ids))$ids=array_filter(explode(',',$ids));
        if(!is_array($ids)||$ids===[]) return ApiEnvelope::error('user_ids required',422);
        $out=[]; foreach($ids as $id) $out[]=$this->store()->get("presence/{$id}.json")?:['user_id'=>(string)$id,'status'=>'unknown'];
        return ApiEnvelope::ok(['presence'=>$out]);
    }
    public function spaceCreate(Request $r): JsonResponse {
        $b=$r->json()??[]; $name=trim((string)($b['name']??'')); if($name==='') return ApiEnvelope::error('name required',422);
        $sid=(string)($b['space_id']??UlidLikeId::generate()); $owner=(string)($b['owner_id']??$b['user_id']??''); $now=ApiEnvelope::nowMs();
        $rec=['space_id'=>$sid,'name'=>$name,'owner_id'=>$owner,'created_ms'=>$now];
        $this->store()->put("spaces/{$sid}.json",$rec);
        if($owner!=='') $this->store()->put("memberships/{$sid}/{$owner}.json",['space_id'=>$sid,'user_id'=>$owner,'role'=>'owner','joined_ms'=>$now]);
        return ApiEnvelope::ok($rec);
    }

    // Crypto prekeys
    public function prekeyPublish(Request $r): JsonResponse {
        $b=$r->json()??[]; $uid=(string)($b['user_id']??''); $ik=(string)($b['identity_key']??'');
        if($uid===''||$ik==='') return ApiEnvelope::error('user_id and identity_key required',422);
        $ot=$b['one_time_prekeys']??[]; if(!is_array($ot))$ot=[];
        $rec=['user_id'=>$uid,'device_id'=>$b['device_id']??'','identity_key'=>$ik,'signed_prekey'=>$b['signed_prekey']??null,'one_time_prekeys'=>$ot,'updated_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("prekeys/{$uid}.json",$rec);
        return ApiEnvelope::ok(['published'=>true,'user_id'=>$uid,'otpk_count'=>count($ot)]);
    }
    public function prekeyFetch(Request $r): JsonResponse {
        $uid=(string)($r->query('user_id')??($r->json()['user_id']??'')); if($uid==='') return ApiEnvelope::error('user_id required',422);
        $rec=$this->store()->get("prekeys/{$uid}.json"); if(!$rec) return ApiEnvelope::error('not found',404);
        $ot=$rec['one_time_prekeys']??[]; $used=null;
        if(is_array($ot)&&$ot!==[]){ $used=array_shift($ot); $rec['one_time_prekeys']=$ot; $this->store()->put("prekeys/{$uid}.json",$rec); }
        return ApiEnvelope::ok(['user_id'=>$uid,'identity_key'=>$rec['identity_key']??null,'signed_prekey'=>$rec['signed_prekey']??null,'one_time_prekey'=>$used,'e2ee'=>true,'alg'=>'x25519-xchacha20poly1305']);
    }
    public function cryptoStatus(Request $r): JsonResponse {
        return ApiEnvelope::ok(['sodium'=>extension_loaded('sodium'),'e2ee_preferred'=>true,'algorithms'=>['x25519','ed25519','xchacha20-poly1305']]);
    }

    // Federation
    public function peers(Request $r): JsonResponse {
        $t=$this->store()->get('topology/nodes.json')??['nodes'=>[]];
        return ApiEnvelope::ok(['peers'=>$t['nodes']??[],'count'=>count($t['nodes']??[])]);
    }
    public function peerRegister(Request $r): JsonResponse {
        $url=rtrim((string)(($r->json()??[])['url']??''),'/');
        if($url===''||!preg_match('#^https?://#i',$url)) return ApiEnvelope::error('valid url required',422);
        $t=$this->store()->get('topology/nodes.json')??['nodes'=>[]]; $nodes=$t['nodes']??[];
        $found=false; foreach($nodes as &$n){ if(($n['url']??'')===$url){ $n['last_seen_ms']=ApiEnvelope::nowMs(); $found=true; break; } }
        unset($n); if(!$found) $nodes[]=['url'=>$url,'host'=>parse_url($url,PHP_URL_HOST),'added_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put('topology/nodes.json',['nodes'=>$nodes,'updated_ms'=>ApiEnvelope::nowMs()]);
        return ApiEnvelope::ok(['registered'=>true,'url'=>$url,'count'=>count($nodes)]);
    }
    public function gossip(Request $r): JsonResponse {
        $id=bin2hex(random_bytes(8));
        $this->store()->put("fed_queue/{$id}.json",['id'=>$id,'payload'=>$r->json()??[],'created_ms'=>ApiEnvelope::nowMs()]);
        return ApiEnvelope::ok(['queued'=>true,'id'=>$id]);
    }

    // Attachments / Push / Moderation
    public function attMeta(Request $r): JsonResponse {
        $b=$r->json()??[]; $id=(string)($b['id']??UlidLikeId::generate('att'));
        $rec=['id'=>$id,'name'=>$b['name']??'file','mime'=>$b['mime']??'application/octet-stream','size'=>(int)($b['size']??0),'hash'=>$b['hash']??'','user_id'=>$b['user_id']??'','created_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("attachments/{$id}.json",$rec); return ApiEnvelope::ok($rec);
    }
    public function attUpload(Request $r): JsonResponse {
        $b=$r->json()??[]; $id=(string)($b['id']??UlidLikeId::generate('att')); $b64=(string)($b['data']??'');
        if($b64==='') return ApiEnvelope::error('data required',422);
        $raw=base64_decode($b64,true); if($raw===false) return ApiEnvelope::error('invalid base64',422);
        if(strlen($raw)>8*1024*1024) return ApiEnvelope::error('too large',413);
        $dir=(defined('DATA_DIR')?DATA_DIR:'').'/attachments/blobs'; if(!is_dir($dir))@mkdir($dir,0750,true);
        file_put_contents($dir.'/'.$id.'.bin',$raw);
        $meta=$this->store()->get("attachments/{$id}.json")??['id'=>$id];
        $meta['size']=strlen($raw); $meta['hash']=hash('sha256',$raw); $meta['blob_path']="attachments/blobs/{$id}.bin";
        $this->store()->put("attachments/{$id}.json",$meta); return ApiEnvelope::ok(['id'=>$id,'size'=>$meta['size'],'hash'=>$meta['hash'],'stored'=>true]);
    }
    public function pushSubscribe(Request $r): JsonResponse {
        $b=$r->json()??[]; if(($b['endpoint']??'')==='') return ApiEnvelope::error('endpoint required',422);
        $id=UlidLikeId::generate('push'); $rec=['id'=>$id,'endpoint'=>$b['endpoint'],'keys'=>$b['keys']??[],'user_id'=>$b['user_id']??'','created_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("push/{$id}.json",$rec); return ApiEnvelope::ok(['subscribed'=>true,'id'=>$id]);
    }
    public function report(Request $r): JsonResponse {
        $b=$r->json()??[]; $id=UlidLikeId::generate('rep');
        $rec=['id'=>$id,'reporter'=>$b['user_id']??'','target'=>$b['target_id']??$b['message_id']??'','reason'=>$b['reason']??'','created_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("reports/{$id}.json",$rec); return ApiEnvelope::ok($rec);
    }
    public function block(Request $r): JsonResponse {
        $b=$r->json()??[]; $uid=(string)($b['user_id']??''); $tid=(string)($b['target_id']??'');
        if($uid===''||$tid==='') return ApiEnvelope::error('user_id and target_id required',422);
        $rec=['user_id'=>$uid,'target_id'=>$tid,'created_ms'=>ApiEnvelope::nowMs()];
        $this->store()->put("blocks/{$uid}/{$tid}.json",$rec); return ApiEnvelope::ok(['blocked'=>true]+$rec);
    }
}
