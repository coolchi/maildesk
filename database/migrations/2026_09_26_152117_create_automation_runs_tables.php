<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->string('contact_email');
            $table->string('status')->default('pending');
            $table->unsignedInteger('current_step_position')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_id', 'status']);
            $table->index(['status', 'due_at']);
        });

        Schema::create('automation_run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_step_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('type');
            $table->string('status')->default('pending');
            $table->json('config')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['automation_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
    }
};
