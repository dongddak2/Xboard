function manduvpn_getPeriodPrices(array $prices): array {
    $legacyFieldByPeriodKey = array_flip(\App\Models\Plan::LEGACY_PERIOD_MAPPING);
    $result = [];
    foreach (\App\Models\Plan::getAvailablePeriods() as $periodKey => $meta) {
        $fieldName = $legacyFieldByPeriodKey[$periodKey] ?? $periodKey;
        $price = $prices[$periodKey] ?? null;
        $result[$fieldName] = $price !== null ? (float) $price * 100 : null;
    }
    return $result;
}

$out = [];

$legacyOnly = manduvpn_getPeriodPrices(['monthly' => 10, 'yearly' => 100]);
$out['legacy_only_month_price'] = $legacyOnly['month_price'];
$out['legacy_only_year_price'] = $legacyOnly['year_price'];
$out['legacy_only_quarter_price_null'] = $legacyOnly['quarter_price'] === null;
$out['legacy_only_days_30_null'] = $legacyOnly['days_30'] === null;
$out['legacy_only_total_keys'] = count($legacyOnly);

$mixed = manduvpn_getPeriodPrices(['monthly' => 10, 'days_30' => 45, 'days_360' => 360]);
$rawLegacyKeyQuirk = manduvpn_getPeriodPrices(['month_price' => 10]); // pre-existing xboard data quirk, see note
$out['raw_legacy_key_quirk_month_price_is_null'] = $rawLegacyKeyQuirk['month_price'] === null;
$out['mixed_month_price'] = $mixed['month_price'];
$out['mixed_days_30'] = $mixed['days_30'];
$out['mixed_days_360'] = $mixed['days_360'];
$out['mixed_days_90_null'] = $mixed['days_90'] === null;
$out['mixed_total_keys'] = count($mixed);
$out['mixed_all_keys'] = array_keys($mixed);

echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
