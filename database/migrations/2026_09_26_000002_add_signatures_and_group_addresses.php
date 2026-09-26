<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional per-mailbox signature; overrides the organization's.
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->text('signature')->nullable();
        });

        Schema::create('group_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique('email');
            $table->index(['organization_id', 'name']);
        });

        Schema::create('group_address_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_address_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['group_address_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_address_members');
        Schema::dropIfExists('group_addresses');

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
