<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Admin\AccountLifecycleController;
use App\Http\Controllers\Admin\AccountUserController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlatformSettingsController;
use App\Http\Controllers\Admin\RevenueController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiAssistController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AutomationController;
use App\Http\Controllers\Billing\MonipayController;
use App\Http\Controllers\BounceController;
use App\Http\Controllers\BroadcastController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainDnsController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\GroupAddressController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxSignatureController;
use App\Http\Controllers\MailDraftController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SegmentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TenantRegistrationController;
use App\Http\Controllers\UnsubscribeController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceInvitationController;
use App\Http\Middleware\RequireFreshPassword;
use App\Services\PlatformSettings;
use App\Services\TenantResolver;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function (Request $request, TenantResolver $tenants) {
    // Workspace hosts are sign-in entry points, not the public marketing site.
    if ($tenants->resolveFromHost($tenants->hostFromRequest($request))) {
        return redirect()->route('login');
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register') && app(PlatformSettings::class)->signupOpen(),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Public send-API guide. The rest of the API reference stays in the app.
Route::get('/docs/send', [DashboardController::class, 'sendDocs'])->name('docs.send');

// Public, signed unsubscribe links embedded in broadcast emails.
Route::get('/unsubscribe/{recipient}', [UnsubscribeController::class, 'show'])
    ->whereNumber('recipient')->middleware('signed:relative')->name('unsubscribe.show');
Route::post('/unsubscribe/{recipient}', [UnsubscribeController::class, 'store'])
    ->whereNumber('recipient')->middleware(['signed:relative', 'throttle:30,1'])->name('unsubscribe.store');

// Workspace student/staff self-registration (tenant host only; gated in controller).
// Not behind `guest`: an authenticated user from another workspace must be able to
// reach /join so we can sign them out and show the registration form.
Route::get('/join', [TenantRegistrationController::class, 'create'])->name('tenant.join');
Route::post('/join', [TenantRegistrationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('tenant.join.store');

// Closed-team email invites (token in the URL is the credential).
Route::get('/invitations/{token}', [WorkspaceInvitationController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('invitations.show');
Route::post('/invitations/{token}', [WorkspaceInvitationController::class, 'accept'])
    ->middleware('throttle:20,1')
    ->name('invitations.accept.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/workspaces/create', [WorkspaceController::class, 'create'])->name('workspaces.create');
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspace/{organization}/switch', [WorkspaceController::class, 'switch'])
        ->name('workspace.switch');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/emails', [EmailController::class, 'index'])->name('emails');
    Route::post('/emails', [EmailController::class, 'store'])->name('emails.store');
    Route::get('/emails/{id}', [EmailController::class, 'show'])->name('emails.show');
    Route::post('/emails/{id}/retry', [EmailController::class, 'retry'])->name('emails.retry');
    Route::post('/attachments', [AttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->whereNumber('attachment')->name('attachments.download');
    Route::get('/inbox', [InboxController::class, 'index'])->name('inbox');
    Route::get('/inbox/sync', [InboxController::class, 'sync'])->middleware('throttle:60,1')->name('inbox.sync');
    Route::get('/inbox/{thread}', [InboxController::class, 'show'])->whereNumber('thread')->name('inbox.show');
    Route::patch('/inbox/{thread}/read', [InboxController::class, 'markRead'])->whereNumber('thread')->name('inbox.read');
    Route::post('/inbox/{thread}/archive', [InboxController::class, 'toggleArchive'])->whereNumber('thread')->name('inbox.archive');
    Route::post('/inbox/{thread}/spam', [InboxController::class, 'toggleSpam'])->whereNumber('thread')->name('inbox.spam');
    Route::post('/inbox/{thread}/trash', [InboxController::class, 'toggleTrash'])->whereNumber('thread')->name('inbox.trash');
    Route::delete('/inbox/{thread}', [InboxController::class, 'destroy'])->whereNumber('thread')->name('inbox.destroy');
    Route::post('/inbox/{thread}/reply', [InboxController::class, 'reply'])->whereNumber('thread')->name('inbox.reply');
    Route::post('/inbox/{thread}/suggest-reply', [InboxController::class, 'suggestReply'])
        ->whereNumber('thread')
        ->middleware('throttle:20,1')
        ->name('inbox.suggest-reply');
    Route::post('/inbox/{thread}/summarize', [AiAssistController::class, 'summarizeThread'])
        ->whereNumber('thread')
        ->middleware('throttle:20,1')
        ->name('inbox.summarize');
    Route::post('/ai/compose', [AiAssistController::class, 'compose'])
        ->middleware('throttle:30,1')
        ->name('ai.compose');
    Route::post('/ai/broadcast', [AiAssistController::class, 'broadcast'])
        ->middleware('throttle:20,1')
        ->name('ai.broadcast');
    Route::post('/ai/automation', [AiAssistController::class, 'automation'])
        ->middleware('throttle:20,1')
        ->name('ai.automation');
    Route::post('/ai/segment', [AiAssistController::class, 'segment'])
        ->middleware('throttle:20,1')
        ->name('ai.segment');
    Route::post('/ai/bounce/{message}', [AiAssistController::class, 'bounce'])
        ->middleware('throttle:20,1')
        ->name('ai.bounce');
    Route::post('/ai/help', [AiAssistController::class, 'help'])
        ->middleware('throttle:20,1')
        ->name('ai.help');
    Route::post('/ai/abuse-scan', [AiAssistController::class, 'abuse'])
        ->middleware('throttle:10,1')
        ->name('ai.abuse');
    Route::post('/inbox/{thread}/forward', [InboxController::class, 'forward'])->whereNumber('thread')->name('inbox.forward');
    Route::get('/spam', [InboxController::class, 'spamIndex'])->name('spam');
    Route::get('/archive', [InboxController::class, 'archiveIndex'])->name('archive');
    Route::get('/trash', [InboxController::class, 'trashIndex'])->name('trash');
    Route::delete('/trash', [InboxController::class, 'emptyTrash'])->name('trash.empty');
    Route::get('/sent', [EmailController::class, 'sent'])->name('sent');
    Route::get('/drafts', [MailDraftController::class, 'index'])->name('drafts');
    Route::post('/drafts', [MailDraftController::class, 'store'])->name('drafts.store');
    Route::put('/drafts/{draft}', [MailDraftController::class, 'update'])->whereNumber('draft')->name('drafts.update');
    Route::delete('/drafts/{draft}', [MailDraftController::class, 'destroy'])->whereNumber('draft')->name('drafts.destroy');
    Route::get('/mailbox/signature', [MailboxSignatureController::class, 'edit'])->name('mailbox.signature');
    Route::put('/mailbox/signature', [MailboxSignatureController::class, 'update'])->name('mailbox.signature.update');
    Route::get('/bounced', [BounceController::class, 'index'])->name('bounced');
    Route::get('/compose', [DashboardController::class, 'compose'])->name('compose');
    Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('broadcasts');
    Route::get('/broadcasts/create', [BroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('/broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
    Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('broadcasts.show');
    Route::delete('/broadcasts/{broadcast}', [BroadcastController::class, 'destroy'])->name('broadcasts.destroy');
    Route::post('/broadcasts/{broadcast}/send', [BroadcastController::class, 'send'])->name('broadcasts.send');
    Route::post('/broadcasts/{broadcast}/cancel', [BroadcastController::class, 'cancel'])->name('broadcasts.cancel');
    Route::get('/automations', [AutomationController::class, 'index'])->name('automations');
    Route::put('/automations/auto-reply', [AutomationController::class, 'updateAutoReply'])->name('automations.auto-reply');
    Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
    Route::post('/automations', [AutomationController::class, 'store'])->name('automations.store');
    Route::get('/automations/{automation}', [AutomationController::class, 'show'])->name('automations.show');
    Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
    Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates');
    Route::put('/templates/designs/default', [TemplateController::class, 'updateDefaultDesign'])->name('templates.design-default');
    Route::put('/templates/designs/color', [TemplateController::class, 'updateDesignColor'])->name('templates.design-color');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/samples/{sample}', [TemplateController::class, 'editSample'])->name('templates.samples.edit');
    Route::post('/templates/samples', [TemplateController::class, 'storeFromSample'])->name('templates.samples.store');
    Route::post('/templates/images', [TemplateController::class, 'uploadImage'])->middleware('throttle:30,1')->name('templates.images');
    Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::post('/templates/{template}/test', [TemplateController::class, 'test'])->name('templates.test');
    Route::post('/templates/{template}/publish', [TemplateController::class, 'publish'])->name('templates.publish');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/audience', [ContactController::class, 'index'])->name('audience');
    Route::post('/audience', [ContactController::class, 'store'])->name('audience.store');
    Route::post('/audience/import', [ContactController::class, 'import'])->middleware('throttle:10,1')->name('audience.import');
    Route::post('/audience/segments', [SegmentController::class, 'store'])->name('audience.segments.store');
    Route::put('/audience/segments/{segment}', [SegmentController::class, 'update'])->whereNumber('segment')->name('audience.segments.update');
    Route::delete('/audience/segments/{segment}', [SegmentController::class, 'destroy'])->whereNumber('segment')->name('audience.segments.destroy');
    Route::post('/audience/segments/{segment}/contacts', [SegmentController::class, 'attach'])->whereNumber('segment')->name('audience.segments.contacts.attach');
    Route::delete('/audience/segments/{segment}/contacts/{contact}', [SegmentController::class, 'detach'])->whereNumber(['segment', 'contact'])->name('audience.segments.contacts.detach');
    Route::patch('/audience/{contact}', [ContactController::class, 'update'])->whereNumber('contact')->name('audience.update');
    Route::delete('/audience/{contact}', [ContactController::class, 'destroy'])->whereNumber('contact')->name('audience.destroy');
    Route::post('/audience/{contact}/suppress', [ContactController::class, 'suppress'])->whereNumber('contact')->name('audience.suppress');
    Route::get('/users', [MailboxController::class, 'index'])->name('users');
    Route::post('/users', [MailboxController::class, 'store'])->name('users.store');
    Route::put('/users/{mailbox}', [MailboxController::class, 'update'])->name('users.update');
    Route::delete('/users/{mailbox}', [MailboxController::class, 'destroy'])->name('users.destroy');
    Route::post('/users/{mailbox}/approve', [MailboxController::class, 'approve'])->name('users.approve');
    Route::post('/users/{mailbox}/reject', [MailboxController::class, 'reject'])->name('users.reject');
    Route::post('/team/{user}/impersonate', [ImpersonationController::class, 'startWorkspace'])
        ->middleware([RequireFreshPassword::class, 'throttle:10,1'])
        ->name('team.impersonate');
    Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics');
    Route::get('/domains', [DomainController::class, 'index'])->name('domains');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/dns/connect', [DomainDnsController::class, 'connect'])->name('domains.dns.connect');
    Route::delete('/domains/{domain}/dns/connect', [DomainDnsController::class, 'disconnect'])->name('domains.dns.disconnect');
    Route::post('/domains/{domain}/dns/apply', [DomainDnsController::class, 'apply'])->name('domains.dns.apply');
    Route::post('/domains/{domain}/dns/enable-receiving', [DomainDnsController::class, 'enableReceiving'])->name('domains.dns.enable-receiving');
    Route::put('/domains/{domain}/dns/records/{record}', [DomainDnsController::class, 'update'])->name('domains.dns.records.update');
    Route::delete('/domains/{domain}/dns/records/{record}', [DomainDnsController::class, 'destroy'])->name('domains.dns.records.destroy');
    Route::get('/logs', [LogController::class, 'index'])->name('logs');
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::get('/api-keys/export', [ApiKeyController::class, 'export'])->name('api-keys.export');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
    Route::post('/api-keys/{apiKey}/revoke', [ApiKeyController::class, 'revoke'])->name('api-keys.revoke');
    Route::post('/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])->middleware('throttle:10,1')->name('api-keys.rotate');
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
    Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->middleware('throttle:10,1')->name('webhooks.rotate');
    Route::get('/groups', [GroupAddressController::class, 'index'])->name('groups');
    Route::post('/groups', [GroupAddressController::class, 'store'])->name('groups.store');
    Route::put('/groups/{group}', [GroupAddressController::class, 'update'])->whereNumber('group')->name('groups.update');
    Route::delete('/groups/{group}', [GroupAddressController::class, 'destroy'])->whereNumber('group')->name('groups.destroy');
    Route::post('/groups/{group}/members', [GroupAddressController::class, 'addMember'])->whereNumber('group')->name('groups.members.store');
    Route::delete('/groups/{group}/members/{member}', [GroupAddressController::class, 'removeMember'])->whereNumber(['group', 'member'])->name('groups.members.destroy');
    Route::get('/suppressions', [SuppressionController::class, 'index'])->name('suppressions');
    Route::post('/suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
    Route::delete('/suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');
    Route::get('/docs', [DashboardController::class, 'docs'])->name('docs');
    Route::get('/help', [HelpController::class, 'index'])->name('help');
    Route::redirect('/settings', '/settings/usage');
    Route::get('/settings/{tab}', [SettingsController::class, 'show'])
        ->whereIn('tab', ['usage', 'billing', 'team', 'users', 'smtp', 'unsubscribe', 'documents', 'signature'])
        ->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::put('/settings/smtp', [SettingsController::class, 'updateSmtp'])->name('settings.smtp.update');
    Route::post('/settings/invitations', [WorkspaceInvitationController::class, 'store'])
        ->middleware('throttle:30,1')->name('invitations.store');
    Route::delete('/settings/invitations/{invitation}', [WorkspaceInvitationController::class, 'destroy'])
        ->whereNumber('invitation')->name('invitations.destroy');
    Route::post('/billing/monipay/initialize', [MonipayController::class, 'initialize'])
        ->middleware('throttle:20,1')->name('billing.monipay.initialize');
    Route::get('/billing/monipay/callback', [MonipayController::class, 'callback'])->name('billing.monipay.callback');

    Route::prefix('admin')->name('admin.')->middleware('platform.admin')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/accounts', [AdminController::class, 'accounts'])->name('accounts');
        Route::get('/accounts/{organization}', [AdminController::class, 'showAccount'])->name('accounts.show');
        Route::put('/accounts/{organization}/provider', [AdminController::class, 'updateAccountProvider'])->name('accounts.provider');
        Route::put('/accounts/{organization}/status', [AdminController::class, 'updateAccountStatus'])->name('accounts.status');
        Route::put('/accounts/{organization}/hosts', [AdminController::class, 'updateAccountHosts'])->name('accounts.hosts');
        Route::get('/subscriptions', [AdminController::class, 'subscriptions'])->name('subscriptions');
        Route::put('/subscriptions/{subscription}', [AdminController::class, 'updateSubscription'])->name('subscriptions.update');
        Route::get('/plans', [AdminController::class, 'plans'])->name('plans');
        Route::post('/plans', [AdminController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->name('plans.update');
        Route::get('/providers', [AdminController::class, 'providers'])->name('providers');
        Route::post('/providers', [AdminController::class, 'storeProvider'])->name('providers.store');
        Route::put('/providers/{mailProvider}', [AdminController::class, 'updateProvider'])->name('providers.update');
        Route::delete('/providers/{mailProvider}', [AdminController::class, 'destroyProvider'])->name('providers.destroy');
        Route::get('/subdomains', [AdminController::class, 'subdomains'])->name('subdomains');
        Route::post('/subdomains', [AdminController::class, 'storeHost'])->name('subdomains.store');
        Route::post('/subdomains/{organizationHost}/verify', [AdminController::class, 'verifyHost'])->name('subdomains.verify');

        // Account lifecycle + membership management (admin panel gap fixes).
        Route::delete('/accounts/{organization}', [AccountLifecycleController::class, 'destroy'])->name('accounts.destroy');
        Route::get('/accounts/{organization}/users', [AccountUserController::class, 'index'])->name('accounts.users');
        Route::post('/accounts/{organization}/users', [AccountUserController::class, 'store'])->name('accounts.users.store');
        Route::put('/accounts/{organization}/users/{user}', [AccountUserController::class, 'update'])->name('accounts.users.update');
        Route::delete('/accounts/{organization}/users/{user}', [AccountUserController::class, 'destroy'])->name('accounts.users.destroy');
        Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])->name('plans.destroy');
        Route::get('/revenue', [RevenueController::class, 'index'])->name('revenue');
        Route::post('/providers/{mailProvider}/test', [AdminController::class, 'testProvider'])
            ->middleware('throttle:10,1')->name('providers.test');
        Route::get('/settings', [PlatformSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [PlatformSettingsController::class, 'update'])->name('settings.update');
        Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'start'])
            ->middleware([RequireFreshPassword::class, 'throttle:10,1'])->name('impersonate');

        Route::get('/system-test', [Admin\SystemTestController::class, 'index'])->name('system-test');
        Route::post('/system-test/start', [Admin\SystemTestController::class, 'start'])->name('system-test.start');
        Route::post('/system-test/poll', [Admin\SystemTestController::class, 'poll'])->name('system-test.poll');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/preferences', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/impersonate/leave', [ImpersonationController::class, 'leave'])->name('impersonate.leave');
    Route::get('/impersonate/resume', [ImpersonationController::class, 'resume'])->name('impersonate.resume');
});

require __DIR__.'/auth.php';
