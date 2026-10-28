/** Naira plan pricing helpers for the admin Plans page. */

export function formatNaira(kobo) {
    if (kobo === null || kobo === undefined || kobo === '') return '₦ —';
    return `₦${(Number(kobo) / 100).toLocaleString('en-NG', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2,
    })}`;
}

/**
 * Keep a volume slider on a real step.
 * The plans modal prefers the second step, but a product with one volume
 * (marketing starter and pro are both 10k contacts) only has index 0.
 * An out-of-range index renders as "undefined contacts".
 */
export function volumeIndexFor(labelCount, preferred = 1) {
    const last = Math.max(0, (Number(labelCount) || 0) - 1);
    const index = Number(preferred);
    const start = Number.isFinite(index) ? index : 0;

    return Math.min(Math.max(0, start), last);
}

/** Paid plans need a naira price of at least minKobo to be payable via Monipay. */
export function isMonipayPayable(plan, minKobo = 5000) {
    if (!plan || Number(plan.price) <= 0) return true;
    if (plan.price_kobo === null || plan.price_kobo === undefined) return false;
    return Number(plan.price_kobo) >= minKobo;
}
