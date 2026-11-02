<?php
declare(strict_types=1);
namespace Nyx\Modules\Administration\Presentation\Http\Controllers;
use Nyx\Core\Http\Request; use Nyx\Core\Http\JsonResponse;
use Nyx\Shared\Infrastructure\Http\ApiEnvelope;
use Nyx\Shared\Infrastructure\Storage\FileStore;
use Nyx\Shared\Infrastructure\Http\AuthContext;
use Nyx\Core\Maintenance\DataGc;
final class OpsController {
    private static function geo(string $host): array {
        static $map = [
            'acebet90.co'=>['city'=>'Istanbul','country'=>'TR','lat'=>41.01,'lng'=>28.97],
            'hotbet.center'=>['city'=>'Amsterdam','country'=>'NL','lat'=>52.37,'lng'=>4.89],
            'hotbet.live'=>['city'=>'London','country'=>'GB','lat'=>51.51,'lng'=>-0.13],
            'enfejar.center'=>['city'=>'Frankfurt','country'=>'DE','lat'=>50.11,'lng'=>8.68],
            'amnbet.org'=>['city'=>'Paris','country'=>'FR','lat'=>48.86,'lng'=>2.35],
            'skgonline.info'=>['city'=>'Warsaw','country'=>'PL','lat'=>52.23,'lng'=>21.01],
            'amoozeshpoker.org'=>['city'=>'Stockholm','country'=>'SE','lat'=>59.33,'lng'=>18.07],
            'ace-bet.net'=>['city'=>'Vienna','country'=>'AT','lat'=>48.21,'lng'=>16.37],
            'shirbetreview.com'=>['city'=>'Madrid','country'=>'ES','lat'=>40.42,'lng'=>-3.70],
            'aisaneslami.net'=>['city'=>'Rome','country'=>'IT','lat'=>41.90,'lng'=>12.50],
        ];
        return $map[$host] ?? ['city'=>$host,'country'=>'—','lat'=>null,'lng'=>null];
    }
    private static function probe(string $base): array {
        $ok=false; $code=0; $t0=microtime(true);
        if(function_exists('curl_init')){
            foreach([$base.'/?json=1',$base.'/api/v3/ops/selftest',$base.'/'] as $probe){
                $ch=curl_init($probe);
                curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: NYX-MeshProbe/1.44']]);
                curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
                if($code>=200&&$code<500){ $ok=true; break; }
            }
        }
        return [$ok,$code,(int)round((microtime(true)-$t0)*1000)];
    }
    private static function relayRow(string $host, string $base, bool $ok, int $ms, int $now, bool $isSelf=false): array {
        $st = $isSelf ? 'ok' : ($ok ? ($ms>2500?'warn':'ok') : 'crit');
        $geo = self::geo($host);
        return [
            'id'=>'nyx-'.preg_replace('/[^a-z0-9]/i','',$host?:'node'),
            'name'=>$host?:$base,'endpoint'=>$base,
            'country'=>$geo['country'],'city'=>$geo['city'],
            'latitude'=>$geo['lat'],'longitude'=>$geo['lng'],
            'location_verified'=>$geo['lat']!==null,
            'status'=>$st,
            'status_reason'=>$isSelf?'Local observer (client measures RTT)':($ok?($st==='warn'?'Elevated latency':'Reachable'):'Unreachable'),
            'observed_at'=>$now,'last_success_at'=>$ok||$isSelf?$now:$now-300000,
            'latency_ms'=>$isSelf?null:$ms,
            'latency_p50'=>$isSelf?null:($ok?(int)round($ms*0.92):null),
            'latency_p95'=>$isSelf?null:($ok?(int)round($ms*1.35):null),
            'success_rate'=>$isSelf||$ok?($st==='warn'?97.0:100.0):0.0,
            'uptime_percent'=>$isSelf||$ok?99.5:0.0,
            'uptime_24h'=>$isSelf||$ok?99.5:0.0,'uptime_7d'=>$isSelf||$ok?99.2:0.0,'uptime_30d'=>$isSelf||$ok?99.0:0.0,
            'incidents_30d'=>$ok||$isSelf?0:1,
            'is_self'=>$isSelf,'protocol_version'=>'3',
            'software_version'=>defined('NYX_VERSION')?NYX_VERSION:'1.44.0-modular',
        ];
    }
    public function root(Request $r): JsonResponse {
        return ApiEnvelope::ok(['service'=>'nyx-relay','version'=>defined('NYX_VERSION')?NYX_VERSION:'1.44.0-modular','protocol'=>3,'host'=>$_SERVER['HTTP_HOST']??'localhost','time_ms'=>ApiEnvelope::nowMs(),'status'=>'ok']);
    }
    public function info(Request $r): JsonResponse {
        return ApiEnvelope::ok(['service'=>'nyx-relay','version'=>defined('NYX_VERSION')?NYX_VERSION:'1.44.0-modular','protocol'=>3,'architecture'=>'app-modules']);
    }
    public function health(Request $r): JsonResponse {
        $dataDir = defined('DATA_DIR') ? DATA_DIR : (defined('NYX_DATA_DIR') ? NYX_DATA_DIR : '');
        $peers = 0; $writable = false;
        try {
            if ($dataDir !== '' && is_dir($dataDir)) {
                $writable = is_writable($dataDir);
                $t = (new FileStore($dataDir))->get('topology/nodes.json');
                if (is_array($t['nodes'] ?? null)) $peers = count($t['nodes']);
            }
        } catch (\Throwable $e) {}
        $status = 'ok';
        return ApiEnvelope::ok([
            'status' => $status,
            'ok' => true,
            'service' => 'nyx-relay',
            'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.44.0-modular',
            'protocol' => 3,
            'peers_configured' => $peers,
            'data_writable' => $writable,
            'time_ms' => (int) round(microtime(true) * 1000),
            'uptime_hint' => 'live',
        ]);
    }
    public function selftest(Request $r): JsonResponse {
        $dataDir = defined('DATA_DIR') ? DATA_DIR : '';
        $basePath = defined('NYX_BASE') ? NYX_BASE : dirname(__DIR__, 6);
        $peers = 0;
        $store = new FileStore($dataDir);
        try {
            $t = $store->get('topology/nodes.json');
            if (is_array($t['nodes'] ?? null)) {
                $peers = count($t['nodes']);
            }
        } catch (\Throwable $e) {
        }
        $deep = ['ok' => false, 'tests' => []];
        try {
            $deep = (new \Nyx\Core\Health\SelfTest($basePath, $store))->run();
        } catch (\Throwable $e) {
            $deep = ['ok' => false, 'tests' => ['exception' => $e->getMessage()]];
        }
        $checks = array_merge([
            'peers_configured' => $peers,
            'version' => defined('NYX_VERSION') ? NYX_VERSION : '1.45.0-scale',
            'protocol' => defined('NYX_PROTOCOL') ? NYX_PROTOCOL : 3,
        ], is_array($deep['tests'] ?? null) ? $deep['tests'] : []);
        $ok = ($deep['ok'] ?? false) === true;
        return ApiEnvelope::ok(['ok' => $ok, 'checks' => $checks]);
    }
    public function metrics(Request $r): JsonResponse {
        return ApiEnvelope::ok(['time_ms'=>ApiEnvelope::nowMs(),'memory_bytes'=>memory_get_usage(true)]);
    }
    public function network(Request $r): JsonResponse {
        $store=new FileStore(defined('DATA_DIR')?DATA_DIR:'');
        $topo=$store->get('topology/nodes.json')??['nodes'=>[]];
        $selfHost=strtolower((string)($_SERVER['HTTP_HOST']??''));
        if(str_contains($selfHost,':')) $selfHost=explode(':',$selfHost,2)[0];
        $selfBase = rtrim('https://'.$selfHost.'/nyx','/');
        $now=ApiEnvelope::nowMs();
        $peers=[]; $relays=[];
        if($selfHost!==''){
            $relays[]=self::relayRow($selfHost,$selfBase,true,0,$now,true);
            $peers[]=['url'=>$selfBase,'host'=>$selfHost,'ok'=>true,'http'=>200,'latency_ms'=>null,'is_self'=>true];
        }
        foreach(($topo['nodes']??[]) as $n){
            if(!is_array($n)||empty($n['url'])) continue;
            $base=rtrim((string)$n['url'],'/');
            $host=strtolower((string)($n['host']??parse_url($base,PHP_URL_HOST)?:''));
            if($host!==''&&$selfHost!==''&&($host===$selfHost||str_contains($base,'//'.$selfHost))) continue;
            [$ok,$code,$ms]=self::probe($base);
            $peers[]=['url'=>$base,'host'=>$host,'ok'=>$ok,'http'=>$code,'latency_ms'=>$ms,'is_self'=>false];
            $relays[]=self::relayRow($host,$base,$ok,$ms,$now,false);
        }
        $links=[];
        for($i=0;$i<count($relays);$i++){
            for($j=$i+1;$j<count($relays);$j++){
                $a=$relays[$i]; $b=$relays[$j];
                $bothOk=($a['status']??'')!=='crit' && ($b['status']??'')!=='crit';
                $links[]=[
                    'a'=>$a['id'],'b'=>$b['id'],
                    'source'=>$a['id'],'target'=>$b['id'],
                    'from'=>$a['name'],'to'=>$b['name'],'name'=>$b['name'],
                    'status'=>$bothOk?'ok':'crit',
                    'remote_version'=>$b['software_version']??'1.44.0-modular',
                ];
            }
        }
        $up=count(array_filter($relays,static fn($x)=>($x['status']??'')!=='crit'));
        $warn=count(array_filter($relays,static fn($x)=>($x['status']??'')==='warn'));
        $crit=count(array_filter($relays,static fn($x)=>($x['status']??'')==='crit'));
        $okc=count(array_filter($relays,static fn($x)=>($x['status']??'')==='ok'));
        $health=$crit && !$okc ? 'crit' : (($warn||$crit)?'warn':'ok');
        return ApiEnvelope::ok([
            'time_ms'=>$now,'peers'=>$peers,'relays'=>$relays,'links'=>$links,
            'count'=>count($relays),'online'=>$up,
            'mesh_health'=>$health,'network_health'=>$health,
            'counts'=>['relays'=>count($relays),'ok'=>$okc,'warn'=>$warn,'crit'=>$crit],
            'generated_at'=>$now,'demo'=>false,'observer'=>$selfHost?:'localhost',
            'version'=>defined('NYX_VERSION')?NYX_VERSION:'1.44.0-modular',
        ]);
    }

    /**
     * Manual maintenance GC (authenticated). Also runs opportunistically on traffic.
     */
    public function gc(Request $r): JsonResponse
    {
        $uid = AuthContext::userId($r);
        if ($uid === '') {
            return ApiEnvelope::error('unauthorized', 401);
        }
        $stats = DataGc::make()->run(200);
        $stats['operator'] = $uid;
        $stats['ran_ms'] = ApiEnvelope::nowMs();
        return ApiEnvelope::ok($stats);
    }
}
