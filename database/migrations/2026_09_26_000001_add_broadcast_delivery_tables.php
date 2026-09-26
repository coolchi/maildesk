<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('audience')->nullable()->after('html');
            $table->string('from')->nullable()->after('audience');
            $table->unsignedInteger('recipient_count')->default(0)->after('status');
            $table->timestamp('queued_at')->nullable()->after('recipient_count');
            $table->timestamp('completed_at')->nullable()->after('sent_at');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('unsubscribed_at')->nullable();
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            // pending, sent, failed, suppressed, skipped
            $table->string('status')->default('pending');
            $table->string('error')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['broadcast_id', 'email']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('unsubscribed_at');
        });

        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['audience', 'from', 'recipient_count', 'queued_at', 'completed_at']);
        });
    }
};
