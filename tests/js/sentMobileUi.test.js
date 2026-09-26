import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(
    resolve(process.cwd(), 'resources/js/Pages/Sent/Index.vue'),
    'utf8',
);

describe('Sent page mobile UI', () => {
    it('uses a horizontally scrollable status chip row', () => {
        expect(source).toContain('data-testid="sent-status-tabs"');
        expect(source).toContain('overflow-x-auto');
        expect(source).toContain('shrink-0');
        expect(source).not.toContain('flex-wrap rounded-full border border-zinc-800 bg-zinc-950 p-1');
    });

    it('renders a card list on mobile and keeps the table on desktop', () => {
        expect(source).toContain('data-testid="sent-mobile-list"');
        expect(source).toContain('data-testid="sent-desktop-table"');
        expect(source).toContain('lg:hidden');
        expect(source).toContain('hidden lg:block');
    });
});
