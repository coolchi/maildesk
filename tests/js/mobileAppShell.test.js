import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const layout = readFileSync(
    resolve(process.cwd(), 'resources/js/Layouts/AppLayout.vue'),
    'utf8',
);
const tabBar = readFileSync(
    resolve(process.cwd(), 'resources/js/Components/MobileTabBar.vue'),
    'utf8',
);
const moreSheet = readFileSync(
    resolve(process.cwd(), 'resources/js/Components/MobileMoreSheet.vue'),
    'utf8',
);
const compose = readFileSync(
    resolve(process.cwd(), 'resources/js/Components/FloatingComposeButton.vue'),
    'utf8',
);
const shell = readFileSync(
    resolve(process.cwd(), 'resources/views/app.blade.php'),
    'utf8',
);
const inbox = readFileSync(
    resolve(process.cwd(), 'resources/js/Pages/Inbox/Index.vue'),
    'utf8',
);
const emailCss = readFileSync(
    resolve(process.cwd(), 'resources/js/lib/emailFrame.js'),
    'utf8',
);

describe('Mobile app shell', () => {
    it('mounts bottom tabs and a More sheet instead of a side drawer', () => {
        expect(layout).toContain("import MobileTabBar from '@/Components/MobileTabBar.vue'");
        expect(layout).toContain("import MobileMoreSheet from '@/Components/MobileMoreSheet.vue'");
        expect(layout).toContain('<MobileTabBar');
        expect(layout).toContain('<MobileMoreSheet');
        expect(layout).toContain('data-testid="mobile-app-header"');
        expect(layout).toContain('@toggle-more="moreSheetOpen = !moreSheetOpen"');
        expect(layout).toContain('data-testid="mobile-workspace-button"');
        expect(layout).not.toContain('data-testid="mobile-menu-button"');
        expect(layout).toContain('hidden h-dvh w-[248px]');
        expect(layout).toContain('lg:flex');
    });

    it('exposes primary mobile destinations including compose and more', () => {
        expect(tabBar).toContain('data-testid="mobile-tab-bar"');
        expect(tabBar).toContain("key: 'inbox'");
        expect(tabBar).toContain("key: 'compose'");
        expect(tabBar).toContain("key: 'more'");
        expect(tabBar).toContain("action: 'compose'");
        expect(tabBar).toContain("emit('toggle-more')");
    });

    it('gives mail-only users Sent and Drafts tabs instead of empty chrome', () => {
        expect(tabBar).toContain("key: 'sent'");
        expect(tabBar).toContain("key: 'drafts'");
        expect(tabBar).toContain("route('sent')");
        expect(tabBar).toContain("route('drafts')");
    });

    it('renders More as an icon grid bottom sheet', () => {
        expect(moreSheet).toContain('data-testid="mobile-more-sheet"');
        expect(moreSheet).toContain('grid grid-cols-4');
        expect(moreSheet).toContain('translate-y-full');
        expect(moreSheet).toContain('data-testid="mobile-more-workspace"');
        expect(moreSheet).toContain('data-testid="mobile-more-sign-out"');
    });

    it('hides the floating compose button on small screens', () => {
        expect(compose).toMatch(/hidden[^"]*lg:inline-flex/);
    });

    it('enables safe-area viewport and standalone web app meta', () => {
        expect(shell).toContain('viewport-fit=cover');
        expect(shell).toContain('apple-mobile-web-app-capable');
        expect(shell).toContain('mobile-web-app-capable');
    });
});

describe('Mobile inbox experience', () => {
    it('uses a list-first mobile flow with a full-screen thread detail', () => {
        expect(inbox).toContain('mobileDetail');
        expect(inbox).toContain('closeMobileDetail');
        expect(inbox).toContain('data-testid="inbox-mobile-back"');
        expect(inbox).toContain('data-testid="inbox-thread-list"');
        expect(inbox).toContain('data-testid="inbox-thread-detail"');
        expect(inbox).toContain('max-lg:fixed');
        expect(inbox).toContain('setHideMobileHeader');
        expect(inbox).toContain('data-testid="inbox-thread-toolbar"');
        expect(inbox).toContain('data-testid="inbox-thread-actions"');
        expect(inbox.indexOf(':items="headerActions"')).toBeGreaterThan(
            inbox.indexOf('data-testid="inbox-thread-toolbar"'),
        );
        expect(inbox.indexOf(':items="headerActions"')).toBeLessThan(
            inbox.indexOf('data-testid="thread-messages"'),
        );
        expect(inbox.indexOf('data-testid="thread-messages"')).toBeLessThan(
            inbox.indexOf('data-testid="inbox-thread-actions"'),
        );
        const detail = inbox.slice(inbox.indexOf('data-testid="inbox-thread-detail"'));
        expect(detail).toContain('active.to');
        const list = inbox.slice(
            inbox.indexOf('data-testid="inbox-thread-list"'),
            inbox.indexOf('data-testid="inbox-thread-detail"'),
        );
        expect(list).not.toContain('thread.to');
    });

    it('keeps thread list text inside the row on narrow screens', () => {
        expect(inbox).toContain('block min-w-0 truncate text-xs text-zinc-500');
        expect(inbox).toContain('min-w-0 flex-1 overflow-hidden');
        expect(inbox).toContain('overflow-x-hidden overflow-y-auto');
    });

    it('prevents email HTML from blowing out the mobile viewport', () => {
        expect(emailCss).toContain('overflow-x: hidden');
        expect(emailCss).toContain('max-width: 100%');
        expect(emailCss).toContain('-webkit-text-size-adjust: 100%');
    });
});
