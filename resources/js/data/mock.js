export const mockWorkspace = {
    id: 1,
    name: 'Acme Mail',
    plan: 'Pro',
    provider: 'resend',
    subdomain: 'acme',
    host: 'acme.maildesk.test',
    color: 'cyan',
};

/** @deprecated Prefer usePlatform().workspaces — kept for older imports */
export const mockWorkspaces = [
    {
        id: 1,
        name: 'Acme Mail',
        plan: 'Pro',
        provider: 'resend',
        color: 'cyan',
        email: 'ade@test.com',
        subdomain: 'acme',
        host: 'acme.maildesk.test',
    },
    {
        id: 2,
        name: 'Northwind Labs',
        plan: 'Free',
        provider: 'resend',
        color: 'violet',
        email: 'jordan@northwind.io',
        subdomain: 'northwind',
        host: 'northwind.maildesk.test',
    },
    {
        id: 3,
        name: 'Harbor FM',
        plan: 'Pro',
        provider: 'smtp',
        color: 'emerald',
        email: 'sam@harbor.fm',
        subdomain: 'harbor',
        host: 'harbor.maildesk.test',
    },
    {
        id: 4,
        name: 'Brightpath',
        plan: 'Pro',
        provider: 'postmark',
        color: 'amber',
        email: 'mia@brightpath.app',
        subdomain: 'brightpath',
        host: 'brightpath.maildesk.test',
    },
    {
        id: 5,
        name: 'Orbit Retail',
        plan: 'Enterprise',
        provider: 'ses',
        color: 'cyan',
        email: 'chris@orbit.store',
        subdomain: 'orbit',
        host: 'orbit.maildesk.test',
    },
    {
        id: 8,
        name: 'Demo Sandbox',
        plan: 'Free',
        provider: 'resend_staging',
        color: 'emerald',
        email: 'demo@maildesk.test',
        subdomain: 'demo',
        host: 'demo.maildesk.test',
    },
];

export const mockStats = {
    sent: 1284,
    delivered: 1198,
    bounced: 42,
    opened: 876,
    received: 314,
    domains: 3,
    api_keys: 4,
    suppressions: 18,
};

/** Workspace mailbox users (custom @domain addresses) */
export const mockMailboxUsers = [
    {
        id: 1,
        name: 'Ada Okonkwo',
        email: 'hello@acme.com',
        role: 'admin',
        status: 'active',
        inbox: true,
        transactional: true,
        marketing: true,
        usage: { sent: 842, limit: 5000, inbox: 128 },
        last_active: '2m ago',
        created: 'Jan 12, 2026',
    },
    {
        id: 2,
        name: 'Support Desk',
        email: 'support@acme.com',
        role: 'staff',
        status: 'active',
        inbox: true,
        transactional: false,
        marketing: false,
        usage: { sent: 0, limit: 0, inbox: 412 },
        last_active: '14m ago',
        created: 'Feb 3, 2026',
    },
    {
        id: 3,
        name: 'Dev Alerts',
        email: 'dev@acme.com',
        role: 'developer',
        status: 'active',
        inbox: true,
        transactional: true,
        marketing: false,
        usage: { sent: 2104, limit: 10000, inbox: 56 },
        last_active: '1h ago',
        created: 'Mar 1, 2026',
    },
    {
        id: 4,
        name: 'Marketing',
        email: 'hello@deskky.com',
        role: 'staff',
        status: 'active',
        inbox: true,
        transactional: false,
        marketing: true,
        usage: { sent: 318, limit: 2000, inbox: 22 },
        last_active: '3h ago',
        created: 'Apr 18, 2026',
    },
    {
        id: 5,
        name: 'Billing Bot',
        email: 'billing@acme.com',
        role: 'developer',
        status: 'inactive',
        inbox: true,
        transactional: true,
        marketing: false,
        usage: { sent: 90, limit: 1000, inbox: 4 },
        last_active: '12d ago',
        created: 'May 9, 2026',
    },
];

export const mockEmails = [
    {
        id: '1',
        to: 'admin@depotterhealthtech.edu.ng',
        from: 'noreply@acme.com',
        from_name: 'Acme Admissions',
        status: 'delivered',
        subject: 'Reset your password',
        sent: '21h ago',
        sent_at: 'Sep 5, 12:18 PM',
        delivered_at: 'Sep 5, 12:18 PM',
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<div style="font-family:Inter,sans-serif;background:#0b1220;padding:24px"><div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden"><div style="background:rgb(34,211,238);padding:24px;color:#042f2e;font-weight:700">MailDesk</div><div style="padding:24px;color:#18181b"><p>Hi there,</p><p>Click the button below to reset your password.</p><p><a href="#" style="display:inline-block;background:#18181b;color:#fff;padding:10px 16px;border-radius:999px;text-decoration:none">Reset password</a></p></div></div></div>',
        text: 'Hi there,\n\nClick the link to reset your password.',
    },
    {
        id: '2',
        to: 'admin@depotterhealthtech.edu.ng',
        from: 'billing@acme.com',
        from_name: 'Acme Billing',
        status: 'delivered',
        subject: 'Your receipt from Acme #1042',
        sent: '21h ago',
        sent_at: 'Sep 5, 11:02 AM',
        delivered_at: 'Sep 5, 11:02 AM',
        direction: 'outbound',
        log: 'POST /emails',
        attachments: ['receipt-1042.pdf'],
        html: '<p>Thanks for your payment of <strong>$49.00</strong>.</p>',
        text: 'Thanks for your payment of $49.00.',
    },
    {
        id: '3',
        to: 'ops@northwind.io',
        from: 'alerts@acme.com',
        from_name: 'Acme Alerts',
        status: 'bounced',
        subject: 'Deployment failed on production',
        sent: '1d ago',
        sent_at: 'Sep 4, 9:41 PM',
        delivered_at: null,
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<p>Deployment <code>web-92f</code> failed health checks.</p>',
        text: 'Deployment web-92f failed health checks.',
    },
    {
        id: '4',
        to: 'maya@studio.co',
        from: 'hello@acme.com',
        from_name: 'Acme',
        status: 'delivered',
        subject: 'Welcome to MailDesk',
        sent: '1d ago',
        sent_at: 'Sep 4, 4:10 PM',
        delivered_at: 'Sep 4, 4:10 PM',
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<p>Welcome aboard. Your API key is ready.</p>',
        text: 'Welcome aboard. Your API key is ready.',
    },
    {
        id: '5',
        to: 'support@acme.com',
        from: 'jordan@client.com',
        from_name: 'Jordan Lee',
        status: 'received',
        subject: 'Question about API rate limits',
        sent: '2d ago',
        sent_at: 'Sep 3, 2:22 PM',
        delivered_at: 'Sep 3, 2:22 PM',
        direction: 'inbound',
        log: 'INBOUND',
        attachments: [],
        html: '<p>Hi team — we are hitting 429s on /emails during peak.</p>',
        text: 'Hi team — we are hitting 429s on /emails during peak.',
    },
    {
        id: '6',
        to: 'lea@brightpath.app',
        from: 'onboarding@acme.com',
        from_name: 'Acme Onboarding',
        status: 'suppressed',
        subject: 'Finish setting up your domain',
        sent: '2d ago',
        sent_at: 'Sep 3, 10:05 AM',
        delivered_at: null,
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<p>Your domain still needs DKIM.</p>',
        text: 'Your domain still needs DKIM.',
    },
    {
        id: '7',
        to: 'team@acme.com',
        from: 'ceo@partner.dev',
        from_name: 'Alex Partner',
        status: 'received',
        subject: 'Partnership proposal',
        sent: '3d ago',
        sent_at: 'Sep 2, 6:18 PM',
        delivered_at: 'Sep 2, 6:18 PM',
        direction: 'inbound',
        log: 'INBOUND',
        attachments: ['deck.pdf'],
        html: '<p>Would love to explore integrating MailDesk.</p>',
        text: 'Would love to explore integrating MailDesk.',
    },
    {
        id: '8',
        to: 'nina@harbor.fm',
        from: 'hello@acme.com',
        from_name: 'Acme',
        status: 'delivered',
        subject: 'Your weekly digest',
        sent: '3d ago',
        sent_at: 'Sep 2, 8:00 AM',
        delivered_at: 'Sep 2, 8:00 AM',
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<p>Here is what happened this week.</p>',
        text: 'Here is what happened this week.',
    },
    {
        id: '9',
        to: 'invalid@mail.invalid',
        from: 'alerts@acme.com',
        from_name: 'Acme Alerts',
        status: 'bounced',
        subject: 'Invoice overdue notice',
        sent: '4d ago',
        sent_at: 'Sep 1, 3:33 PM',
        delivered_at: null,
        direction: 'outbound',
        log: 'POST /emails',
        attachments: [],
        html: '<p>Your invoice is overdue.</p>',
        text: 'Your invoice is overdue.',
    },
    {
        id: '10',
        to: 'dev@acme.com',
        from: 'github@noreply.com',
        from_name: 'GitHub',
        status: 'received',
        subject: '[maildesk] CI failed on main',
        sent: '5d ago',
        sent_at: 'Aug 31, 9:12 AM',
        delivered_at: 'Aug 31, 9:12 AM',
        direction: 'inbound',
        log: 'INBOUND',
        attachments: [],
        html: '<p>CI failed on main branch.</p>',
        text: 'CI failed on main branch.',
    },
];

export const mockMetricPoints = [
    { day: 'Aug 23', delivered: 18, bounced: 2, delayed: 1 },
    { day: 'Aug 25', delivered: 22, bounced: 3, delayed: 2 },
    { day: 'Aug 27', delivered: 14, bounced: 1, delayed: 0 },
    { day: 'Aug 29', delivered: 4, bounced: 2, delayed: 7 },
    { day: 'Aug 31', delivered: 28, bounced: 4, delayed: 3 },
    { day: 'Sep 02', delivered: 35, bounced: 5, delayed: 2 },
    { day: 'Sep 04', delivered: 41, bounced: 6, delayed: 4 },
    { day: 'Sep 06', delivered: 38, bounced: 3, delayed: 2 },
];

export const mockBounceSeries = [2, 1, 4, 3, 6, 2, 5, 4, 3, 2, 1, 3, 2, 4, 1];
export const mockComplaintSeries = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];

export const mockUsage = {
    transactional: {
        plan: 'Pro',
        monthly: { used: 1284, limit: 50000, renews: 'Oct 1, 2026' },
        daily: 'Unlimited',
    },
    marketing: {
        plan: 'Free',
        contacts: { used: 4, limit: 1000 },
        segments: { used: 1, limit: 3 },
        broadcasts: 'Unlimited',
    },
    team: {
        plan: 'Pro',
        seats: { used: 2, limit: 10 },
    },
};

export const mockThreads = [
    {
        id: 1,
        subject: 'Question about API rate limits',
        snippet: 'Hi team — we are hitting 429s on /emails during peak…',
        from: 'jordan@client.com',
        unread: true,
        label: 'Support',
        messages: 3,
        updated: '2d ago',
    },
    {
        id: 2,
        subject: 'Partnership proposal',
        snippet: 'Would love to explore integrating MailDesk into…',
        from: 'ceo@partner.dev',
        unread: true,
        label: 'Sales',
        messages: 1,
        updated: '3d ago',
    },
    {
        id: 3,
        subject: 'Domain verification help',
        snippet: 'Our SPF record looks correct but status is still pending.',
        from: 'it@retail.co',
        unread: false,
        label: 'Deliverability',
        messages: 5,
        updated: '5d ago',
    },
    {
        id: 4,
        subject: 'Webhook delivery failures',
        snippet: 'Seeing intermittent 502s from our endpoint overnight.',
        from: 'sre@northwind.io',
        unread: false,
        label: 'Engineering',
        messages: 4,
        updated: '1w ago',
    },
];

export const mockNotifications = [
    {
        id: 'n1',
        type: 'inbox',
        title: 'New message from jordan@client.com',
        body: 'Question about API rate limits',
        time: '12m ago',
        unread: true,
        href: '/inbox',
        threadId: 1,
    },
    {
        id: 'n2',
        type: 'inbox',
        title: 'New message from ceo@partner.dev',
        body: 'Partnership proposal',
        time: '3h ago',
        unread: true,
        href: '/inbox',
        threadId: 2,
    },
    {
        id: 'n3',
        type: 'bounce',
        title: 'Hard bounce detected',
        body: 'invalid@mail.invalid · invoice #4821',
        time: '5h ago',
        unread: true,
        href: '/logs',
    },
    {
        id: 'n4',
        type: 'domain',
        title: 'Domain verified',
        body: 'mail.acme.com passed SPF and DKIM checks',
        time: '1d ago',
        unread: false,
        href: '/domains',
    },
    {
        id: 'n5',
        type: 'webhook',
        title: 'Webhook delivery failed',
        body: 'staging.acme.com returned 502',
        time: '2d ago',
        unread: false,
        href: '/webhooks',
    },
];

export const mockDomains = [
    {
        id: 1,
        name: 'acme.com',
        status: 'verified',
        region: 'us-east-1',
        created: 'Jan 12, 2026',
        records: { spf: true, dkim: true, dmarc: true },
    },
    {
        id: 2,
        name: 'mail.acme.com',
        status: 'verified',
        region: 'us-east-1',
        created: 'Feb 3, 2026',
        records: { spf: true, dkim: true, dmarc: false },
    },
    {
        id: 3,
        name: 'updates.acme.com',
        status: 'pending',
        region: 'eu-west-1',
        created: 'Aug 28, 2026',
        records: { spf: true, dkim: false, dmarc: false },
    },
];

export const mockApiKeys = [
    {
        id: 1,
        name: 'Production',
        prefix: 'md_prod_8f2a',
        permission: 'Full access',
        domain: 'All domains',
        created: '1mo ago',
        last_used: '2 minutes ago',
    },
    {
        id: 2,
        name: 'Staging',
        prefix: 'md_stag_91bc',
        permission: 'Sending access',
        domain: 'acme.com',
        created: '3w ago',
        last_used: '4 hours ago',
    },
    {
        id: 3,
        name: 'Local Dev',
        prefix: 'md_dev_44ee',
        permission: 'Full access',
        domain: 'All domains',
        created: '2mo ago',
        last_used: 'Never',
    },
    {
        id: 4,
        name: 'CI Pipeline',
        prefix: 'md_ci_0aa1',
        permission: 'Sending access',
        domain: 'mail.acme.com',
        created: '1w ago',
        last_used: '1 day ago',
    },
];

export const mockWebhooks = [
    {
        id: 1,
        endpoint: 'https://api.acme.com/hooks/mail',
        events: ['email.sent', 'email.delivered', 'email.bounced'],
        status: 'enabled',
        created: '2d ago',
        last_delivery: 'Success · 12m ago',
        secret: 'whsec_mock_a1b2c3d4e5f6',
    },
    {
        id: 2,
        endpoint: 'https://hooks.slack.com/services/T00/B00/xxx',
        events: ['email.bounced', 'email.complained'],
        status: 'enabled',
        created: '1w ago',
        last_delivery: 'Success · 3h ago',
        secret: 'whsec_mock_slack_9f2a',
    },
    {
        id: 3,
        endpoint: 'https://staging.acme.com/webhooks/mail',
        events: ['email.received'],
        status: 'disabled',
        created: '3d ago',
        last_delivery: 'Failed · 502 · 1d ago',
        secret: 'whsec_mock_stage_44ee',
    },
];

export const mockLogs = [
    {
        id: 1,
        level: 'info',
        event: 'email.delivered',
        message: 'Message md_msg_9182 delivered to admin@…',
        time: '12m ago',
    },
    {
        id: 2,
        level: 'warn',
        event: 'email.bounced',
        message: 'Hard bounce for invalid@mail.invalid',
        time: '1h ago',
    },
    {
        id: 3,
        level: 'info',
        event: 'email.opened',
        message: 'Open tracked for invoice · #4821',
        time: '2h ago',
    },
    {
        id: 4,
        level: 'error',
        event: 'webhook.failed',
        message: 'POST https://staging.acme.com/webhooks/resend → 502',
        time: '1d ago',
    },
    {
        id: 5,
        level: 'info',
        event: 'email.sent',
        message: 'Queued welcome · day 1 via Resend',
        time: '1d ago',
    },
    {
        id: 6,
        level: 'warn',
        event: 'email.complained',
        message: 'Spam complaint from spamtrap@example.com',
        time: '2d ago',
    },
    {
        id: 7,
        level: 'info',
        event: 'domain.verified',
        message: 'mail.acme.com verified via DKIM',
        time: '3d ago',
    },
];

export const mockMetrics = {
    series: [
        { day: 'Mon', sent: 120, delivered: 112, opened: 68 },
        { day: 'Tue', sent: 145, delivered: 138, opened: 79 },
        { day: 'Wed', sent: 132, delivered: 124, opened: 71 },
        { day: 'Thu', sent: 168, delivered: 159, opened: 92 },
        { day: 'Fri', sent: 190, delivered: 181, opened: 104 },
        { day: 'Sat', sent: 88, delivered: 84, opened: 41 },
        { day: 'Sun', sent: 76, delivered: 72, opened: 38 },
    ],
    deliveryRate: 93.3,
    openRate: 68.4,
    bounceRate: 3.2,
};

export const mockTemplates = [
    {
        id: 1,
        name: 'Password reset',
        subject: 'Reset your MailDesk password',
        updated: '2d ago',
        status: 'published',
        accent: '#22d3ee',
        html: `<div style="font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;background:#0a0a0a;padding:40px 16px;">
  <div style="max-width:480px;margin:0 auto;background:#111;border:1px solid #27272a;border-radius:16px;overflow:hidden;">
    <div style="padding:28px 32px 8px;">
      <div style="font-size:13px;letter-spacing:.12em;text-transform:uppercase;color:#22d3ee;font-weight:600;">MailDesk</div>
      <h1 style="margin:16px 0 8px;font-size:24px;line-height:1.25;color:#fafafa;font-weight:600;">Reset your password</h1>
      <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#a1a1aa;">Hi {{first_name}}, we received a request to reset the password for your account.</p>
      <a href="{{reset_url}}" style="display:inline-block;background:#22d3ee;color:#09090b;text-decoration:none;font-weight:600;font-size:14px;padding:12px 20px;border-radius:999px;">Reset password</a>
      <p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#71717a;">This link expires in {{expires_in}}. If you didn’t ask for this, you can ignore this email.</p>
    </div>
    <div style="padding:16px 32px 24px;border-top:1px solid #27272a;margin-top:28px;">
      <p style="margin:0;font-size:12px;color:#52525b;">© MailDesk · Secure account access</p>
    </div>
  </div>
</div>`,
    },
    {
        id: 2,
        name: 'Welcome series · Day 1',
        subject: 'Welcome to MailDesk — let’s get you sending',
        updated: '1w ago',
        status: 'published',
        accent: '#34d399',
        html: `<div style="font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;background:#f4f4f5;padding:40px 16px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.06);">
    <div style="height:6px;background:linear-gradient(90deg,#22d3ee,#34d399);"></div>
    <div style="padding:32px;">
      <p style="margin:0 0 4px;font-size:13px;color:#71717a;">Hey {{first_name}},</p>
      <h1 style="margin:0 0 12px;font-size:26px;line-height:1.2;color:#18181b;">You’re in. Let’s ship your first email.</h1>
      <p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#52525b;">MailDesk gives you a Resend-ready API, shared inbox, and deliverability tools in one workspace.</p>
      <ol style="margin:0 0 24px;padding-left:18px;color:#3f3f46;font-size:14px;line-height:1.7;">
        <li>Verify your domain</li>
        <li>Create an API key</li>
        <li>Send a test with POST /emails</li>
      </ol>
      <a href="{{dashboard_url}}" style="display:inline-block;background:#18181b;color:#fff;text-decoration:none;font-weight:600;font-size:14px;padding:12px 18px;border-radius:10px;">Open dashboard →</a>
    </div>
  </div>
</div>`,
    },
    {
        id: 3,
        name: 'Receipt',
        subject: 'Your receipt from Acme #{{invoice_id}}',
        updated: '3w ago',
        status: 'draft',
        accent: '#a78bfa',
        html: `<div style="font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,sans-serif;background:#09090b;padding:40px 16px;">
  <div style="max-width:480px;margin:0 auto;color:#fafafa;">
    <div style="font-size:13px;color:#a78bfa;font-weight:600;letter-spacing:.08em;text-transform:uppercase;">Receipt</div>
    <h1 style="margin:12px 0 4px;font-size:22px;font-weight:600;">Payment received</h1>
    <p style="margin:0 0 24px;color:#a1a1aa;font-size:14px;">Thanks {{first_name}}. Here’s a summary of your charge.</p>
    <div style="border:1px solid #27272a;border-radius:12px;overflow:hidden;">
      <div style="display:flex;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #27272a;font-size:14px;">
        <span style="color:#a1a1aa;">Amount</span><strong>{{amount}}</strong>
      </div>
      <div style="display:flex;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #27272a;font-size:14px;">
        <span style="color:#a1a1aa;">Invoice</span><span>#{{invoice_id}}</span>
      </div>
      <div style="display:flex;justify-content:space-between;padding:14px 16px;font-size:14px;">
        <span style="color:#a1a1aa;">Date</span><span>{{paid_at}}</span>
      </div>
    </div>
    <p style="margin:24px 0 0;font-size:12px;color:#52525b;">Questions? Reply to this email anytime.</p>
  </div>
</div>`,
    },
];

export const mockAudience = [
    {
        id: 1,
        email: 'maya@studio.co',
        first_name: 'Maya',
        last_name: 'Chen',
        status: 'subscribed',
        added: 'Jan 4',
        properties: { company: 'Studio Co', plan: 'Pro' },
    },
    {
        id: 2,
        email: 'ops@northwind.io',
        first_name: 'Sam',
        last_name: 'Rivera',
        status: 'subscribed',
        added: 'Jan 12',
        properties: { company: 'Northwind', plan: 'Free' },
    },
    {
        id: 3,
        email: 'lea@brightpath.app',
        first_name: 'Lea',
        last_name: 'Nguyen',
        status: 'unsubscribed',
        added: 'Feb 2',
        properties: { company: 'Brightpath', plan: 'Pro' },
    },
    {
        id: 4,
        email: 'nina@harbor.fm',
        first_name: 'Nina',
        last_name: 'Park',
        status: 'subscribed',
        added: 'Mar 18',
        properties: { company: 'Harbor', plan: 'Pro' },
    },
    {
        id: 5,
        email: 'jordan@client.com',
        first_name: 'Jordan',
        last_name: 'Lee',
        status: 'subscribed',
        added: 'Apr 2',
        properties: { company: 'Client Inc', plan: 'Free' },
    },
];

export const mockAudienceStats = {
    all: 5,
    subscribers: 4,
    unsubscribers: 1,
};

export const mockProperties = [
    { id: 1, name: 'company', type: 'string', contacts: 5 },
    { id: 2, name: 'plan', type: 'string', contacts: 5 },
    { id: 3, name: 'signup_source', type: 'string', contacts: 2 },
    { id: 4, name: 'lifetime_value', type: 'number', contacts: 1 },
];

export const mockSegments = [
    {
        id: 1,
        name: 'Pro customers',
        count: 3,
        rule: 'plan equals Pro',
        updated: '2d ago',
    },
    {
        id: 2,
        name: 'Unengaged 30d',
        count: 1,
        rule: 'last_open older than 30 days',
        updated: '1w ago',
    },
];

export const mockTopics = [
    {
        id: 1,
        name: 'Product updates',
        description: 'Changelog and feature releases',
        subscribers: 4,
    },
    {
        id: 2,
        name: 'Billing',
        description: 'Invoices and payment notices',
        subscribers: 5,
    },
    {
        id: 3,
        name: 'Newsletter',
        description: 'Monthly roundup',
        subscribers: 2,
    },
];

export const mockPlans = {
    transactional: {
        volumes: [10000, 50000, 100000, 250000, 500000, 1000000],
        labels: ['10k', '50k', '100k', '250k', '500k', '1M+'],
        keys: {
            free: 'tx_free',
            pro: 'tx_pro',
            enterprise: 'tx_enterprise',
        },
        prices: {
            usd: {
                free: [0, 0, 0, 0, 0, 0],
                pro: [0, 20, 35, 80, 160, null],
            },
            ngn: {
                free: [0, 0, 0, 0, 0, 0],
                pro: [0, 15000, 15000, 45000, 45000, null],
            },
        },
    },
    marketing: {
        volumes: [1000, 5000, 10000, 25000, 50000, 100000, 200000],
        labels: ['1,000', '5,000', '10,000', '25,000', '50,000', '100,000', '200,000+'],
        keys: {
            free: 'mkt_free',
            pro: 'mkt_pro',
            enterprise: 'mkt_enterprise',
        },
        prices: {
            usd: {
                free: [0, 0, 0, 0, 0, 0, 0],
                pro: [20, 40, 70, 140, 250, 450, null],
            },
            ngn: {
                free: [0, 0, 0, 0, 0, 0, 0],
                pro: [15000, 15000, 15000, 45000, 45000, 45000, null],
            },
        },
    },
};

export const mockBroadcasts = [
    {
        id: 1,
        name: 'March product update',
        status: 'sent',
        audience: 'Product updates',
        recipients: 412,
        open_rate: '38%',
        sent: '2d ago',
    },
    {
        id: 2,
        name: 'Welcome drip · blast',
        status: 'scheduled',
        audience: 'All subscribed',
        recipients: 1284,
        open_rate: '—',
        sent: 'Tomorrow 9:00 AM',
    },
    {
        id: 3,
        name: 'Billing notice draft',
        status: 'draft',
        audience: 'Pro customers',
        recipients: 86,
        open_rate: '—',
        sent: '—',
    },
];

export const mockAutomations = [
    {
        id: 1,
        name: 'Untitled Automation',
        status: 'disabled',
        runs: 0,
        created: '2min ago',
        trigger: 'user.created',
        steps: [
            { type: 'trigger', label: 'When user.created' },
            { type: 'delay', label: 'Wait 1 hour' },
            { type: 'email', label: 'Send Welcome series · Day 1' },
        ],
    },
    {
        id: 2,
        name: 'Welcome sequence',
        status: 'enabled',
        runs: 128,
        created: '3d ago',
        trigger: 'user.created',
        steps: [
            { type: 'trigger', label: 'When user.created' },
            { type: 'email', label: 'Send Welcome series · Day 1' },
            { type: 'delay', label: 'Wait 2 days' },
            { type: 'email', label: 'Send Tips email' },
        ],
    },
    {
        id: 3,
        name: 'Failed payment nudge',
        status: 'enabled',
        runs: 42,
        created: '1w ago',
        trigger: 'invoice.past_due',
        steps: [
            { type: 'trigger', label: 'When invoice.past_due' },
            { type: 'delay', label: 'Wait 1 day' },
            { type: 'email', label: 'Send payment reminder' },
        ],
    },
];

export const mockAutomationEvents = [
    {
        id: 1,
        name: 'user.created',
        created: '3d ago',
        description: 'Fired when a new user signs up',
    },
    {
        id: 2,
        name: 'invoice.past_due',
        created: '1w ago',
        description: 'Fired when an invoice becomes past due',
    },
    {
        id: 3,
        name: 'domain.created',
        created: 'just now',
        description: 'Fired when a sending domain is added',
    },
];
