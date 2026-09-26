/** Form presets for adding a platform mail provider. Not sample accounts. */
export const providerDriverPresets = {
    resend: {
        label: 'Resend',
        type: 'api',
        apiBase: 'https://api.resend.com',
        regions: ['us-east-1', 'eu-west-1'],
        features: ['Transactional', 'Inbound', 'Webhooks', 'Domains'],
        description: 'API provider for transactional and marketing mail.',
        configKeys: [
            { key: 'API_KEY', secret: true, placeholder: 're_...' },
            { key: 'WEBHOOK_SECRET', secret: true, placeholder: 'whsec_...' },
        ],
    },
    postmark: {
        label: 'Postmark',
        type: 'api',
        apiBase: 'https://api.postmarkapp.com',
        regions: ['us-east-1'],
        features: ['Transactional', 'Templates', 'Webhooks'],
        description: 'High-deliverability transactional specialist.',
        configKeys: [
            { key: 'SERVER_TOKEN', secret: true, placeholder: '••••••••' },
            { key: 'ACCOUNT_TOKEN', secret: true, placeholder: 'optional' },
        ],
    },
    sendgrid: {
        label: 'SendGrid',
        type: 'api',
        apiBase: 'https://api.sendgrid.com',
        regions: ['us-east-1', 'eu-west-1'],
        features: ['Transactional', 'Marketing', 'Webhooks'],
        description: 'Twilio SendGrid for high-volume sends.',
        configKeys: [{ key: 'API_KEY', secret: true, placeholder: 'SG....' }],
    },
    ses: {
        label: 'Amazon SES',
        type: 'api',
        apiBase: 'https://email.{region}.amazonaws.com',
        regions: ['us-east-1', 'eu-west-1', 'ap-southeast-1'],
        features: ['Transactional', 'Dedicated IPs'],
        description: 'AWS SES for cost-efficient scale.',
        configKeys: [
            { key: 'ACCESS_KEY_ID', secret: false, placeholder: 'AKIA...' },
            { key: 'SECRET_ACCESS_KEY', secret: true, placeholder: '••••••••' },
            { key: 'REGION', secret: false, placeholder: 'us-east-1' },
        ],
    },
    smtp: {
        label: 'Generic SMTP',
        type: 'smtp',
        apiBase: null,
        regions: ['global'],
        features: ['SMTP relay', 'Custom hosts'],
        description: 'Bring-your-own SMTP for legacy stacks.',
        configKeys: [
            { key: 'HOST', secret: false, placeholder: 'smtp.example.com' },
            { key: 'PORT', secret: false, placeholder: '587' },
            { key: 'USERNAME', secret: false, placeholder: 'user' },
            { key: 'PASSWORD', secret: true, placeholder: '••••••••' },
            { key: 'ENCRYPTION', secret: false, placeholder: 'tls' },
        ],
    },
    mailgun: {
        label: 'Mailgun',
        type: 'api',
        apiBase: 'https://api.mailgun.net',
        regions: ['us', 'eu'],
        features: ['Transactional', 'Inbound'],
        description: 'Mailgun transactional and inbound mail.',
        configKeys: [
            { key: 'API_KEY', secret: true, placeholder: 'key-...' },
            { key: 'DOMAIN', secret: false, placeholder: 'mg.example.com' },
            { key: 'REGION', secret: false, placeholder: 'us' },
        ],
    },
};

/**
 * @param {Array<{id:string,accounts?:number}>} providers
 * @param {Array<{provider:string}>} accounts
 */
export function recountProviderTenants(providers, accounts) {
    const counts = {};
    for (const account of accounts) {
        if (!account.provider) {
            continue;
        }
        counts[account.provider] = (counts[account.provider] || 0) + 1;
    }
    for (const provider of providers) {
        provider.accounts = counts[provider.id] || 0;
    }

    return providers;
}

/**
 * @param {{provider:string}|null|undefined} account
 * @param {Array<{id:string,status:string}>} providers
 */
export function providerHealthForAccount(account, providers) {
    if (!account?.provider) {
        return { ok: false, reason: 'missing', provider: null };
    }
    const provider = providers.find((item) => item.id === account.provider) || null;
    if (!provider) {
        return { ok: false, reason: 'orphaned', provider: null };
    }
    if (provider.status !== 'active') {
        return { ok: false, reason: 'disabled', provider };
    }

    return { ok: true, reason: 'active', provider };
}
