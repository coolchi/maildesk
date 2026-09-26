<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('default_provider')->default('resend');
            $table->timestamps();
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner'); // owner, admin, member
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
        });

        Schema::create('provider_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // resend, smtp, ses, postmark
            $table->longText('credentials')->nullable(); // encrypted via cast; not JSON
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
        });

        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('pending'); // pending, verified, failed
            $table->string('provider')->nullable();
            $table->string('provider_domain_id')->nullable();
            $table->json('dns_records')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('domain_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->unique();
            $table->string('display_name')->nullable();
            $table->string('type')->default('shared'); // shared, personal, alias
            $table->timestamps();
        });

        Schema::create('threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('snippet', 500)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mailbox_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction'); // inbound, outbound
            $table->string('status')->default('queued'); // queued, sent, delivered, bounced, failed, received
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('message_id_header')->nullable()->index();
            $table->string('in_reply_to')->nullable();
            $table->json('references')->nullable();
            $table->string('from_email');
            $table->string('from_name')->nullable();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->json('reply_to')->nullable();
            $table->string('subject');
            $table->longText('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('headers')->nullable();
            $table->json('tags')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'direction', 'created_at']);
            $table->index(['provider', 'provider_message_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->timestamps();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('key_prefix', 16);
            $table->string('key_hash');
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('key_prefix');
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('url');
            $table->string('secret')->nullable();
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event');
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->string('status')->default('pending'); // pending, success, failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('threads');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('domains');
        Schema::dropIfExists('provider_configs');
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
