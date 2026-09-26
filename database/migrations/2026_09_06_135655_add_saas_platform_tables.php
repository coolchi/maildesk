<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('remember_token');
        });

        Schema::create('mail_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('driver'); // resend, postmark, sendgrid, ses, smtp, mailgun
            $table->string('type')->default('api'); // api, smtp
            $table->string('status')->default('active'); // active, disabled
            $table->boolean('is_default')->default(false);
            $table->string('api_base')->nullable();
            $table->json('regions')->nullable();
            $table->json('features')->nullable();
            $table->text('description')->nullable();
            $table->longText('config')->nullable(); // encrypted via cast; not JSON
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('product'); // transactional, marketing
            $table->string('name');
            $table->unsignedInteger('price')->default(0); // cents or dollars as int dollars to match mock
            $table->string('interval')->default('month');
            $table->unsignedInteger('emails')->nullable();
            $table->unsignedInteger('contacts')->nullable();
            $table->unsignedInteger('seats')->nullable();
            $table->boolean('featured')->default(false);
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('status')->default('active')->after('default_provider');
            $table->string('plan')->default('Free')->after('status');
            $table->string('product')->default('transactional')->after('plan');
            $table->string('subdomain')->nullable()->unique()->after('product');
            $table->string('custom_domain')->nullable()->after('subdomain');
            $table->unsignedInteger('mrr')->default(0)->after('custom_domain');
            $table->unsignedInteger('seats')->default(1)->after('mrr');
            $table->unsignedBigInteger('emails_30d')->default(0)->after('seats');
            $table->string('region')->default('us-east-1')->after('emails_30d');
            $table->string('owner_name')->nullable()->after('region');
            $table->string('owner_email')->nullable()->after('owner_name');
            $table->foreignId('mail_provider_id')->nullable()->after('owner_email')
                ->constrained('mail_providers')->nullOnDelete();
            $table->timestamp('provisioned_at')->nullable()->after('mail_provider_id');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name');
            $table->string('product');
            $table->string('status')->default('active');
            $table->unsignedInteger('price')->default(0);
            $table->string('renews_at')->nullable();
            $table->unsignedInteger('seats')->default(1);
            $table->timestamps();
        });

        Schema::create('organization_hosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain')->nullable();
            $table->string('host');
            $table->string('status')->default('provisioning'); // active, pending_dns, provisioning
            $table->boolean('ssl')->default(false);
            $table->boolean('is_custom')->default(false);
            $table->timestamps();

            $table->unique('host');
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_hosts');
        Schema::dropIfExists('subscriptions');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_provider_id');
            $table->dropColumn([
                'status',
                'plan',
                'product',
                'subdomain',
                'custom_domain',
                'mrr',
                'seats',
                'emails_30d',
                'region',
                'owner_name',
                'owner_email',
                'provisioned_at',
            ]);
        });

        Schema::dropIfExists('plans');
        Schema::dropIfExists('mail_providers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
