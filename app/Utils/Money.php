<?php

namespace App\Utils;

/**
 * ManduVPN's currency-minor-unit contract, centralized so no call site
 * hardcodes ×100 or a KRW exception.
 *
 * CNY: fen (1 CNY = 100 fen), matching xboard's existing Order.total_amount
 * convention untouched by this session's earlier work (e.g.
 * OrderController::save()'s `* 100`).
 *
 * KRW: TossPayments V2's `amount` is a plain integer count of won —
 * confirmed from the official docs (docs.tosspayments.com/reference,
 * payments/confirm): `amount` (number, required) = "결제할 금액입니다",
 * with no separate minor-unit multiplier anywhere in the V2 payment-flow or
 * widget-integration guides. So KRW's minor unit IS the won itself — no
 * ×100 for KRW, ever.
 */
class Money
{
    private const ZERO_DECIMAL_CURRENCIES = ['KRW'];

    public static function toMinorUnit(string $currency, float|int $amount): int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return (int) round($amount);
        }
        return (int) round($amount * 100);
    }

    public static function fromMinorUnit(string $currency, int $minorUnitAmount): float|int
    {
        if (in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true)) {
            return $minorUnitAmount;
        }
        return round($minorUnitAmount / 100, 2);
    }
}
