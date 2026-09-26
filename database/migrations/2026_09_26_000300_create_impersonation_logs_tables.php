<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            // Nullable so deleting a user never erases the audit trail.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->text('reason');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 32)->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'ended_at']);
            $table->index(['organization_id', 'started_at']);
            $table->index('user_id');
        });

        Schema::create('impersonation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonation_log_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->string('route_name')->nullable();
            $table->string('path', 2048);
            $table->unsignedSmallInteger('status')->nullable();
            $table->boolean('blocked')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['impersonation_log_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_actions');
        Schema::dropIfExists('impersonation_logs');
    }
};
