<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Validation\ValidationException;

/**
 * Naira (NGN) plan pricing. Admins enter naira (decimals allowed); it is
 * stored as integer kobo in plans.price_kobo, which BillingService charges
 * as-is. Monipay's minimum lives in config('services.monipay.min_amount').
 */
class PlanNairaPrice
{
    /**
     * @return array<int, string>
     */
    public static function rules(): array
    {
        return ['nullable', 'numeric', 'min:0', 'max:100000000'];
    }

    public static function minKobo(): int
    {
        return (int) config('services.monipay.min_amount', 5000);
    }

    public static function toKobo(mixed $naira): ?int
    {
        if ($naira === null || $naira === '') {
            return null;
        }

        return (int) round(((float) $naira) * 100);
    }

    public static function toNaira(?int $kobo): ?float
    {
        return $kobo === null ? null : round($kobo / 100, 2);
    }

    /**
     * A paid plan (USD price > 0) with a naira price must meet the minimum.
     *
     * @throws ValidationException
     */
    public static function assertMinimum(int $usdPrice, mixed $naira, string $field = 'price_ngn'): void
    {
        $kobo = self::toKobo($naira);

        if ($usdPrice > 0 && $kobo !== null && $kobo < self::minKobo()) {
            throw ValidationException::withMessages([
                $field => 'The naira price must be at least ₦'.number_format(self::minKobo() / 100, 2).' (Monipay minimum) for a paid plan.',
            ]);
        }
    }

    /**
     * Paid plans with no naira price, or one under the minimum, cannot be
     * checked out through Monipay (flagged in the admin Plans page).
     */
    public static function payableViaMonipay(Plan $plan): bool
    {
        if ((int) $plan->price <= 0) {
            return true;
        }

        return $plan->price_kobo !== null && (int) $plan->price_kobo >= self::minKobo();
    }
}
