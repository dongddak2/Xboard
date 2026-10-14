<?php
final class ManduPlanRules {
  public static function basic(int $devices): array {
    if ($devices < 1 || $devices > 5) throw new InvalidArgumentException('device count');
    $cny=[];$krw=[];
    for ($month=1;$month<=12;$month++) { $key='days_'.($month*30); $cny[$key]=30*$devices*$month; $krw[$key]=6000*$devices*$month; }
    return ['name'=>'베이직 · '.$devices.'대','service_tier'=>'legacy','audience'=>'public','device_limit'=>$devices,'transfer_enable'=>999999,'prices'=>$cny,'prices_krw'=>$krw];
  }
  public static function samePrices(array $actual,array $expected): bool { ksort($actual);ksort($expected);return $actual==$expected; }
  public static function master(): array {
    return ['name'=>'마스터 · 관리자 지정','service_tier'=>'master','audience'=>'admin_only','device_limit'=>null,
      'transfer_enable'=>999999,'show'=>0,'sell'=>0,'renew'=>0,'prices'=>[],'prices_krw'=>[]];
  }
}
