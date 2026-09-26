import { describe, expect, it } from 'vitest';
import { formatNaira, isMonipayPayable } from '../../resources/js/utils/planPricing.js';

describe('plan naira pricing', () => {
    it('formats kobo as naira', () => {
        expect(formatNaira(500000)).toBe('₦5,000');
        expect(formatNaira(5050)).toBe('₦50.5');
        expect(formatNaira(null)).toBe('₦ —');
    });

    it('flags paid plans without a naira price or under the minimum', () => {
        expect(isMonipayPayable({ price: 20, price_kobo: null })).toBe(false);
        expect(isMonipayPayable({ price: 20, price_kobo: 4999 })).toBe(false);
        expect(isMonipayPayable({ price: 20, price_kobo: 5000 })).toBe(true);
        expect(isMonipayPayable({ price: 0, price_kobo: null })).toBe(true);
    });
});
