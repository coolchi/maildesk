import { describe, expect, it } from 'vitest';
import { slugify } from '@/lib/slugify';

describe('slugify', () => {
    it('lowercases and turns spaces and underscores into hyphens', () => {
        expect(slugify('Harbor Labs')).toBe('harbor-labs');
        expect(slugify('ACME_Mail Team')).toBe('acme-mail-team');
    });

    it('strips punctuation', () => {
        expect(slugify("Joe's Café & Co.!")).toBe('joes-caf-co');
        expect(slugify('a.b/c@d')).toBe('abcd');
    });

    it('strips accents and other non-ASCII characters', () => {
        expect(slugify('Ünïcødé Ltd')).toBe('ncd-ltd');
        expect(slugify('東京 Team 🚀')).toBe('team');
    });

    it('trims leading and trailing hyphens', () => {
        expect(slugify('  --Acme--  ')).toBe('acme');
        expect(slugify('_acme_')).toBe('acme');
    });

    it('collapses repeated hyphens', () => {
        expect(slugify('acme -- mail   team')).toBe('acme-mail-team');
        expect(slugify('a & b')).toBe('a-b');
    });

    it('caps the result at 63 characters without a trailing hyphen', () => {
        expect(slugify('a'.repeat(100))).toHaveLength(63);
        expect(slugify(`${'a'.repeat(62)} bcd`)).toBe('a'.repeat(62));
    });

    it('returns an empty string for empty input', () => {
        expect(slugify('')).toBe('');
        expect(slugify(null)).toBe('');
        expect(slugify('!!!')).toBe('');
    });
});
