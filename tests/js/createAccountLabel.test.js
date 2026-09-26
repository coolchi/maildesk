import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const source = readFileSync(resolve(process.cwd(), 'resources/js/Layouts/AppLayout.vue'), 'utf8');

describe('AppLayout "Create account" wording', () => {
    it('labels the switcher item, modal title and submit button "Create account"', () => {
        expect(source).toMatch(/<Plus :size="14" class="text-cyan-300" \/>\s*Create account\s*<\/button>/);
        expect(source).toContain('title="Create account"');
        expect(source).toMatch(/@click="createTeam">\s*Create account\s*<\/button>/);
    });

    it('no longer shows "Create team" to users', () => {
        expect(source).not.toMatch(/create (a |new )?team/i);
    });

    it('keeps the internal names and the workspaces.store route', () => {
        expect(source).toContain('const createTeam = () =>');
        expect(source).toContain('showCreateTeam');
        expect(source).toContain("route('workspaces.store')");
    });
});
