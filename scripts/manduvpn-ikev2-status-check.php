<?php
namespace Illuminate\Database\Eloquent { class Model { protected $attributes=[]; public function __get($n){return $this->attributes[$n]??null;} public function __set($n,$v){$this->attributes[$n]=$v;} } }
namespace Illuminate\Support\Facades { class Cache { static function get($key){return $GLOBALS['cache'][$key]??null;} } }
namespace App\Utils { class CacheKey { static function get($key,$id){return "$key:$id";} } }
namespace App\Models { class IkeV2Node { static function find($id){return $GLOBALS['health']??null;} } }
namespace {
require __DIR__.'/../app/Models/Server.php';
function check($type,$health,$cache,$expected,$label) { $GLOBALS['health']=$health;$GLOBALS['cache']=$cache; $s=new \App\Models\Server; $s->id=3;$s->type=$type;$s->updated_at=1; $s->loadServerStatus(); if($s->is_online!==$expected)throw new \Exception($label); echo "PASS $label\n"; }
$recent=(object)['token_hash'=>'hash','last_seen_at'=>time()-10,'applied_revision'=>'rev','last_error'=>null];
check('ikev2',$recent,[],1,'recent heartbeat without legacy cache');
$stale=clone $recent;$stale->last_seen_at=time()-301;check('ikev2',$stale,[],0,'stale heartbeat');
$revoked=clone $recent;$revoked->token_hash=null;check('ikev2',$revoked,['SERVER_IKEV2_LAST_CHECK_AT:3'=>time()],0,'revoked token overrides cache');
check('hysteria',$recent,['SERVER_HYSTERIA_LAST_CHECK_AT:3'=>time()],1,'other protocols retain cache');
check('ikev2',null,['SERVER_IKEV2_LAST_CHECK_AT:3'=>time()],1,'legacy node retains cache');
}
