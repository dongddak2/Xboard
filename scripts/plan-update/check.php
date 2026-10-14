<?php
require __DIR__.'/PlanRules.php';
for($n=1;$n<=5;$n++) {
  $p=ManduPlanRules::basic($n);
  if($p['transfer_enable']!==999999 || $p['device_limit']!==$n || $p['service_tier']!=='legacy' || count($p['prices'])!==12) throw new Exception('plan definition');
  for($m=1;$m<=12;$m++) {
    if($p['prices']['days_'.($m*30)]!==30*$n*$m || $p['prices_krw']['days_'.($m*30)]!==6000*$n*$m) throw new Exception('price');
  }
}
if(!ManduPlanRules::samePrices(['b'=>2,'a'=>1],['a'=>1,'b'=>2])) throw new Exception('price comparison');
echo "PASS 5 device plans, 60 periods, independent KRW/CNY, 999999GB\n";
