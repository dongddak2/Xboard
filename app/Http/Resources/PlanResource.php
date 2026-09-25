<?php


namespace App\Http\Resources;

use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    private const PRICE_MULTIPLIER = 100;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'group_id' => $this->resource['group_id'],
            'name' => $this->resource['name'],
            'service_tier' => $this->resource['service_tier'] ?? null, // ManduVPN PP-B: explicit tier for the purchase UI (never parsed from names)
            'device_limit' => $this->resource['device_limit'] ?? null, // ManduVPN PLAN-UI-1A: device count for the purchase UI (never parsed from names)
            'content' => $this->formatContent(),
            ...$this->getPeriodPrices(),
            ...$this->getKrwPeriodPrices(),
            'capacity_limit' => $this->getFormattedCapacityLimit(),
            'transfer_enable' => $this->resource['transfer_enable'],
            'speed_limit' => $this->resource['speed_limit'],
            'show' => (bool) $this->resource['show'],
            'sell' => (bool) $this->resource['sell'],
            'renew' => (bool) $this->resource['renew'],
            'reset_traffic_method' => $this->resource['reset_traffic_method'],
            'sort' => $this->resource['sort'],
            'created_at' => $this->resource['created_at'],
            'updated_at' => $this->resource['updated_at']
        ];
    }

    /**
     * Get transformed period prices for every period Plan::getAvailablePeriods()
     * knows about (legacy month/quarter/... periods AND the newer "days_N"
     * periods), keyed by field name.
     *
     * Legacy periods keep their original legacy field name (month_price,
     * quarter_price, ...) for backward compatibility with existing API
     * consumers. New "days_N" periods are exposed under their own key
     * (days_30, days_60, ...) since no legacy name exists for them.
     *
     * @return array<string, float|null>
     */
    protected function getPeriodPrices(): array
    {
        $legacyFieldByPeriodKey = array_flip(Plan::LEGACY_PERIOD_MAPPING);
        $prices = $this->resource['prices'] ?? [];

        $result = [];
        foreach (Plan::getAvailablePeriods() as $periodKey => $meta) {
            $fieldName = $legacyFieldByPeriodKey[$periodKey] ?? $periodKey;
            $price = $prices[$periodKey] ?? null;
            $result[$fieldName] = $price !== null
                ? (float) $price * self::PRICE_MULTIPLIER
                : null;
        }
        return $result;
    }

    /**
     * KRW prices for the ManduVPN day-based periods only, from the separate
     * `prices_krw` column (independent fixed sale price — NOT derived from
     * the CNY `prices` values at read time; see the migration/model comment
     * on Plan::$casts). Exposed under new `days_N_krw` fields so existing
     * consumers of the CNY-only `days_N` fields above are unaffected.
     * Legacy periods (month_price, ...) never had a KRW price designed for
     * them and are intentionally not included here.
     *
     * @return array<string, float|null>
     */
    protected function getKrwPeriodPrices(): array
    {
        $pricesKrw = $this->resource['prices_krw'] ?? [];

        $result = [];
        foreach (Plan::DAYS_BASED_PERIODS as $days) {
            $periodKey = "days_{$days}";
            $price = $pricesKrw[$periodKey] ?? null;
            $result["{$periodKey}_krw"] = $price !== null ? (float) $price : null;
        }
        return $result;
    }

    /**
     * Get formatted capacity limit value
     *
     * @return int|string|null
     */
    protected function getFormattedCapacityLimit(): int|string|null
    {
        $limit = $this->resource['capacity_limit'];

        return match (true) {
            $limit === null => null,
            $limit <= 0 => __('Sold out'),
            default => (int) $limit,
        };
    }

    /**
     * Format content with template variables
     *
     * @return string
     */
    protected function formatContent(): string
    {
        $content = $this->resource['content'] ?? '';

        $replacements = [
            '{{transfer}}' => $this->resource['transfer_enable'],
            '{{speed}}' => $this->resource['speed_limit'] === NULL ? __('No Limit') : $this->resource['speed_limit'],
            '{{devices}}' => $this->resource['device_limit'] === NULL ? __('No Limit') : $this->resource['device_limit'],
            '{{reset_method}}' => $this->getResetMethodText(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    /**
     * Get reset method text
     *
     * @return string
     */
    protected function getResetMethodText(): string
    {
        $method = $this->resource['reset_traffic_method'];

        if ($method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $method = admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }
        return match ($method) {
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => __('First Day of Month'),
            Plan::RESET_TRAFFIC_MONTHLY => __('Monthly'),
            Plan::RESET_TRAFFIC_NEVER => __('Never'),
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => __('First Day of Year'),
            Plan::RESET_TRAFFIC_YEARLY => __('Yearly'),
            default => __('Monthly')
        };
    }
}
