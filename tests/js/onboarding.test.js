import { describe, expect, it } from 'vitest';
import { nextStep, resolveSteps } from '../../resources/js/composables/useOnboarding.js';

describe('onboarding progress', () => {
    it('uses server completion over the local fallback', () => {
        const steps = resolveSteps(
            { domain: true, apiKey: false, send: true, webhook: false },
            { apiKey: true, webhook: true },
        );
        expect(steps).toEqual({ domain: true, apiKey: false, send: true, webhook: false });
    });

    it('falls back to local progress when the server sends nothing', () => {
        expect(resolveSteps(null, { domain: true })).toEqual({
            domain: true, apiKey: false, send: false, webhook: false,
        });
    });

    it('advances to the first unfinished step and ends at null', () => {
        expect(nextStep({ domain: true, apiKey: false, send: false, webhook: false })).toBe('apiKey');
        expect(nextStep({ domain: true, apiKey: true, send: false, webhook: true })).toBe('send');
        expect(nextStep({ domain: true, apiKey: true, send: true, webhook: true })).toBeNull();
    });
});
