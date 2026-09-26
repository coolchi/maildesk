<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_key')->nullable();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('monipay');
            $table->string('reference')->unique(); // ours: md_<ulid>
            $table->string('provider_reference')->nullable()->index(); // order_id / reference returned by the provider
            $table->string('access_code')->nullable();
            $table->string('trans_id')->nullable()->index();
            $table->unsignedBigInteger('amount'); // kobo
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('pending'); // pending, paid, failed, abandoned
            $table->string('channel')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->json('verify_payload')->nullable(); // whitelisted fields only
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('plans', function (Blueprint $table) {
            // Explicit NGN price in kobo; when null it is derived from `price`.
            $table->unsignedBigInteger('price_kobo')->nullable()->after('price');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('current_period_ends_at')->nullable()->after('renews_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('current_period_ends_at');
        });

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('price_kobo');
        });

        Schema::dropIfExists('payments');
    }
};
