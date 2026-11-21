<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The app is now called Tracing Board. Only a subject still holding the old wording is changed, so a superadmin's
 * own wording is kept.
 */
return new class extends Migration
{
    private const OLD_SUBJECT = '{{club_name}} has invited you to Club Manager';

    private const NEW_SUBJECT = '{{club_name}} has invited you to Tracing Board';

    public function up(): void
    {
        DB::table('default_email_templates')
            ->where('template_key', 'account_invitation')
            ->where('subject', self::OLD_SUBJECT)
            ->update(['subject' => self::NEW_SUBJECT]);
    }

    public function down(): void
    {
        DB::table('default_email_templates')
            ->where('template_key', 'account_invitation')
            ->where('subject', self::NEW_SUBJECT)
            ->update(['subject' => self::OLD_SUBJECT]);
    }
};
