export const SUBDOMAIN_MAX_LENGTH = 63;

/**
 * Suggest a DNS-label-safe subdomain from free text (e.g. a team name).
 * The server still normalises and validates whatever is submitted.
 */
export function slugify(value, maxLength = SUBDOMAIN_MAX_LENGTH) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/[\s_]+/g, '-')
        .replace(/[^a-z0-9-]/g, '')
        .replace(/-{2,}/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, maxLength)
        .replace(/-+$/, '');
}
