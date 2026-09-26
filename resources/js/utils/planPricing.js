/** Naira plan pricing helpers for the admin Plans page. */

export function formatNaira(kobo) {
    if (kobo === null || kobo === undefined || kobo === '') return '₦ —';
    return `₦${(Number(kobo) / 100).toLocaleString('en-NG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    })}`;
}

/** Paid plans need a naira price of at least minKobo to be payable via Monipay. */
export function isMonipayPayable(plan, minKobo = 5000) {
    if (!plan || Number(plan.price) <= 0) return true;
    if (plan.price_kobo === null || plan.price_kobo === undefined) return false;
    return Number(plan.price_kobo) >= minKobo;
}
