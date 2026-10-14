<?php
namespace {
class Q {
 public string $fields='*',$table=''; public array $conditions=[];
 function select($x){$this->fields=$x;return $this;} function from($x){$this->table=$x;return $this;}
 function where($k,$v){$this->conditions[]=['AND',$k.'='.$GLOBALS['pdo']->quote((string)$v)];return $this;}
 function whereRaw($x){$this->conditions[]=['AND',$x];return $this;}
 function whereNull($k){$this->conditions[]=['AND',$k.' IS NULL'];return $this;}
 function orWhereIn($k,$f){$sub=new Q;$f($sub);$this->conditions[]=['OR',$k.' IN ('.$sub->sql().')'];return $this;}
 function orWhereNotIn($k,$f){$sub=new Q;$f($sub);$this->conditions[]=['OR',$k.' NOT IN ('.$sub->sql().')'];return $this;}
 function predicate(){return implode(' ',array_map(fn($x,$i)=>($i?$x[0].' ':'').$x[1],$this->conditions,array_keys($this->conditions)));}
 function sql(){return 'SELECT '.$this->fields.' FROM '.$this->table.($this->conditions?' WHERE '.$this->predicate():'');}
 function exists(){return $GLOBALS['pdo']->query($this->sql())->fetchColumn()!==false;}
}
}
namespace App\Models {
 class User {public $plan_id,$is_partner=0;function __construct($id){$this->plan_id=$id;}}
 class Plan {public $service_tier,$audience;function __construct($tier,$audience){$this->service_tier=$tier;$this->audience=$audience;} static function whereKey($id){return (new \Q)->select('id')->from('v2_plan')->where('id',$id);}}
}
namespace {
$pdo=new \PDO('sqlite::memory:');$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE v2_plan(id INTEGER,service_tier TEXT,audience TEXT); INSERT INTO v2_plan VALUES(1,'master','public'),(2,'master','admin_only'),(3,'pro','public')");
$pdo->exec("CREATE TABLE v2_user(id INTEGER,plan_id INTEGER,group_id INTEGER,banned INTEGER,expired_at INTEGER,u INTEGER,d INTEGER,transfer_enable INTEGER); INSERT INTO v2_user VALUES(1,2,2,0,NULL,200,200,1),(2,3,2,0,NULL,200,200,1),(3,1,2,0,NULL,200,200,1),(4,2,2,0,1,200,200,1),(5,2,2,1,NULL,200,200,1),(6,3,2,0,NULL,0,0,100),(7,2,9,0,NULL,200,200,1)");
require __DIR__.'/../app/Services/MasterAccess.php';
require __DIR__.'/../app/Services/PlanService.php';
use App\Services\MasterAccess;use App\Services\PlanService;use App\Models\Plan;use App\Models\User;
$q=new Q;MasterAccess::includeUnlimitedTraffic($q);
$ids=$pdo->query('SELECT id FROM v2_user WHERE ('.$q->predicate().') AND group_id=2 AND banned=0 AND (expired_at IS NULL OR expired_at>=100)')->fetchAll(PDO::FETCH_COLUMN);
if($ids!==[1,6])throw new \Exception('unlimited must not bypass expiry, ban, groups, or grant to public master');
if(!MasterAccess::isUser(new User(2))||MasterAccess::isUser(new User(1))||MasterAccess::isUser(new User(3)))throw new \Exception('membership');
foreach(['public','partner','admin_only'] as $audience){$p=new Plan('master',$audience);$u=new User(2);$u->is_partner=1;if(PlanService::canUserAccessPlan(null,$p)||PlanService::canUserAccessPlan($u,$p)||PlanService::canUserPayPlan($u,$p))throw new \Exception('master purchase gate');}
if(!PlanService::canUserAccessPlan(null,new Plan('pro','public')))throw new \Exception('normal public access');
echo "PASS master: private membership, unlimited data, expiry/ban/group protection, purchase/payment refusal, normal plans unchanged\n";
}
