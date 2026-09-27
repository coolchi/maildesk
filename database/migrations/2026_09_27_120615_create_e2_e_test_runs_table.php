<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('e2e_test_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending');
            $table->string('token', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('cli');
            $table->json('steps')->nullable();
            $table->json('timings')->nullable();
            $table->text('error')->nullable();
            $table->string('error_hint')->nullable();
            $table->string('failed_step')->nullable();
            $table->foreignId('outbound_message_id')->nullable();
            $table->foreignId('inbound_message_id')->nullable();
            $table->boolean('include_events')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('e2e_test_runs');
    }
};
