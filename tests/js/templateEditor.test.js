import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

const editor = readFileSync('resources/js/Pages/Templates/Edit.vue', 'utf8');

describe('Template editor actions', () => {
    it('sends a real test and publish request', () => {
        expect(editor).toContain("route('templates.test', props.id)");
        expect(editor).toContain("route('templates.publish', props.id)");
        expect(editor).not.toContain('queued (mock)');
    });
});
