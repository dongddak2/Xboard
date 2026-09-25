$days = [30,60,90,120,150,180,210,240,270,300,330,360];
$baseCny = [
    'basic'   => [1=>45, 2=>70, 3=>95, 4=>120, 5=>145],
    'premium' => [1=>60, 2=>95, 3=>130, 4=>165, 5=>200],
];

function manduvpn_price($base, $d) {
    if ($d === 360) {
        return intdiv($base * 12 * 2, 3);
    }
    $months = $d / 30;
    if ($d <= 60) {
        return (int) round($base * $months);
    }
    if ($d <= 150) {
        return (int) round($base * $months * 0.9);
    }
    return (int) round($base * $months * 0.8);
}

$table = [];
foreach (['basic','premium'] as $tier) {
    foreach ([1,2,3,4,5] as $devices) {
        $row = ['tier' => $tier, 'devices' => $devices];
        foreach ($days as $d) {
            $cny = manduvpn_price($baseCny[$tier][$devices], $d);
            $row["days_{$d}_cny"] = $cny;
            $row["days_{$d}_krw"] = $cny * 200;
        }
        $table[] = $row;
    }
}

$expected = [
    ['basic',1,30,45], ['basic',1,90,122], ['basic',1,180,216], ['basic',1,360,360],
    ['basic',5,30,145], ['basic',5,90,392], ['basic',5,180,696], ['basic',5,360,1160],
    ['premium',1,30,60], ['premium',1,90,162], ['premium',1,180,288], ['premium',1,360,480],
    ['premium',5,30,200], ['premium',5,90,540], ['premium',5,180,960], ['premium',5,360,1600],
];
$checks = [];
foreach ($expected as [$tier, $dev, $d, $exp]) {
    $got = manduvpn_price($baseCny[$tier][$dev], $d);
    $checks[] = ["{$tier}/{$dev}dev/{$d}d", $exp, $got, $exp === $got];
}

echo json_encode(['checks' => $checks, 'all_pass' => collect($checks)->every(fn($c) => $c[3])], JSON_PRETTY_PRINT) . PHP_EOL;
echo "=====FULL TABLE=====" . PHP_EOL;
echo json_encode($table, JSON_PRETTY_PRINT) . PHP_EOL;
