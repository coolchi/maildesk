<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->boolean('imap_enabled')->default(false)->after('send_limit');
            $table->string('stalwart_account_id')->nullable()->after('imap_enabled');
            $table->string('stalwart_domain_id')->nullable()->after('stalwart_account_id');
            $table->text('imap_password')->nullable()->after('stalwart_domain_id');
            $table->unsignedBigInteger('imap_last_uid')->nullable()->after('imap_password');
            $table->string('imap_uidvalidity')->nullable()->after('imap_last_uid');
        });
    }

    public function down(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn([
                'imap_enabled',
                'stalwart_account_id',
                'stalwart_domain_id',
                'imap_password',
                'imap_last_uid',
                'imap_uidvalidity',
            ]);
        });
    }
};
