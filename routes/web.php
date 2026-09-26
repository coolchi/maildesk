<?php

use App\Http\Controllers\AdminController;
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
use App\Http\Controllers\InboxController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SuppressionController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register') && app(\App\Services\PlatformSettings::class)->signupOpen(),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
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
    Route::get('/inbox/{thread}', [InboxController::class, 'show'])->whereNumber('thread')->name('inbox.show');
    Route::patch('/inbox/{thread}/read', [InboxController::class, 'markRead'])->whereNumber('thread')->name('inbox.read');
    Route::post('/inbox/{thread}/reply', [InboxController::class, 'reply'])->whereNumber('thread')->name('inbox.reply');
    Route::get('/sent', [EmailController::class, 'sent'])->name('sent');
    Route::get('/bounced', [BounceController::class, 'index'])->name('bounced');
    Route::get('/compose', [DashboardController::class, 'compose'])->name('compose');
    Route::get('/broadcasts', [BroadcastController::class, 'index'])->name('broadcasts');
    Route::get('/broadcasts/create', [BroadcastController::class, 'create'])->name('broadcasts.create');
    Route::post('/broadcasts', [BroadcastController::class, 'store'])->name('broadcasts.store');
    Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show'])->name('broadcasts.show');
    Route::delete('/broadcasts/{broadcast}', [BroadcastController::class, 'destroy'])->name('broadcasts.destroy');
    Route::get('/automations', [AutomationController::class, 'index'])->name('automations');
    Route::get('/automations/create', [AutomationController::class, 'create'])->name('automations.create');
    Route::get('/automations/{automation}', [AutomationController::class, 'show'])->name('automations.show');
    Route::put('/automations/{automation}', [AutomationController::class, 'update'])->name('automations.update');
    Route::delete('/automations/{automation}', [AutomationController::class, 'destroy'])->name('automations.destroy');
    Route::get('/templates', [TemplateController::class, 'index'])->name('templates');
    Route::post('/templates', [TemplateController::class, 'store'])->name('templates.store');
    Route::get('/templates/{template}/edit', [TemplateController::class, 'edit'])->name('templates.edit');
    Route::put('/templates/{template}', [TemplateController::class, 'update'])->name('templates.update');
    Route::delete('/templates/{template}', [TemplateController::class, 'destroy'])->name('templates.destroy');
    Route::get('/audience', [ContactController::class, 'index'])->name('audience');
    Route::post('/audience', [ContactController::class, 'store'])->name('audience.store');
    Route::delete('/audience/{contact}', [ContactController::class, 'destroy'])->name('audience.destroy');
    Route::post('/audience/{contact}/suppress', [ContactController::class, 'suppress'])->name('audience.suppress');
    Route::get('/users', [MailboxController::class, 'index'])->name('users');
    Route::post('/users', [MailboxController::class, 'store'])->name('users.store');
    Route::put('/users/{mailbox}', [MailboxController::class, 'update'])->name('users.update');
    Route::delete('/users/{mailbox}', [MailboxController::class, 'destroy'])->name('users.destroy');
    Route::get('/metrics', [MetricsController::class, 'index'])->name('metrics');
    Route::get('/domains', [DomainController::class, 'index'])->name('domains');
    Route::post('/domains', [DomainController::class, 'store'])->name('domains.store');
    Route::get('/domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    Route::post('/domains/{domain}/verify', [DomainController::class, 'verify'])->name('domains.verify');
    Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    Route::post('/domains/{domain}/dns/connect', [DomainDnsController::class, 'connect'])->name('domains.dns.connect');
    Route::delete('/domains/{domain}/dns/connect', [DomainDnsController::class, 'disconnect'])->name('domains.dns.disconnect');
    Route::post('/domains/{domain}/dns/apply', [DomainDnsController::class, 'apply'])->name('domains.dns.apply');
    Route::put('/domains/{domain}/dns/records/{record}', [DomainDnsController::class, 'update'])->name('domains.dns.records.update');
    Route::delete('/domains/{domain}/dns/records/{record}', [DomainDnsController::class, 'destroy'])->name('domains.dns.records.destroy');
    Route::get('/logs', [LogController::class, 'index'])->name('logs');
    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
    Route::put('/api-keys/{apiKey}', [ApiKeyController::class, 'update'])->name('api-keys.update');
    Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('/suppressions', [SuppressionController::class, 'index'])->name('suppressions');
    Route::post('/suppressions', [SuppressionController::class, 'store'])->name('suppressions.store');
    Route::delete('/suppressions/{suppression}', [SuppressionController::class, 'destroy'])->name('suppressions.destroy');
    Route::get('/docs', [DashboardController::class, 'docs'])->name('docs');
    Route::redirect('/settings', '/settings/usage');
    Route::get('/settings/{tab}', [SettingsController::class, 'show'])
        ->whereIn('tab', ['usage', 'billing', 'smtp', 'unsubscribe', 'documents'])
        ->name('settings');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
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
        Route::delete('/accounts/{organization}', [\App\Http\Controllers\Admin\AccountLifecycleController::class, 'destroy'])->name('accounts.destroy');
        Route::get('/accounts/{organization}/users', [\App\Http\Controllers\Admin\AccountUserController::class, 'index'])->name('accounts.users');
        Route::post('/accounts/{organization}/users', [\App\Http\Controllers\Admin\AccountUserController::class, 'store'])->name('accounts.users.store');
        Route::put('/accounts/{organization}/users/{user}', [\App\Http\Controllers\Admin\AccountUserController::class, 'update'])->name('accounts.users.update');
        Route::delete('/accounts/{organization}/users/{user}', [\App\Http\Controllers\Admin\AccountUserController::class, 'destroy'])->name('accounts.users.destroy');
        Route::delete('/plans/{plan}', [\App\Http\Controllers\Admin\PlanController::class, 'destroy'])->name('plans.destroy');
        Route::get('/revenue', [\App\Http\Controllers\Admin\RevenueController::class, 'index'])->name('revenue');
        Route::post('/providers/{mailProvider}/test', [AdminController::class, 'testProvider'])
            ->middleware('throttle:10,1')->name('providers.test');
        Route::get('/settings', [\App\Http\Controllers\Admin\PlatformSettingsController::class, 'index'])->name('settings');
        Route::put('/settings', [\App\Http\Controllers\Admin\PlatformSettingsController::class, 'update'])->name('settings.update');
    });
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
