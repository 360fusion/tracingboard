<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The invitation email says who invited the person, as what, what the account is for (worded for a hall, a group or
 * a lodge) and how to sign in later. Only a template still holding the previous words is changed, so a superadmin's
 * own wording is kept.
 */
return new class extends Migration
{
    private const OLD_BODY = '<p>Dear {{member_name}},</p><p>{{club_name}} has invited you to see its meetings, reply to summonses and book dinners online.</p><p><a href="{{invite_url}}" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">Choose your password</a></p><p>This link works for {{expiry_days}} days.</p>';

    public const NEW_BODY = '<p>Dear {{member_name}},</p>'
        .'<p>{{inviter_name}} has invited you to join <strong>{{club_name}}</strong> on Tracing Board, as {{role_name}}.</p>'
        .'<p>With your account you can:</p>{{what_you_can_do}}'
        .'<p>To get started, choose a password:</p>'
        .'<p><a href="{{invite_url}}" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:bold;">Choose your password</a></p>'
        .'<p>This link works for {{expiry_days}} days. If it has run out, ask whoever invited you to send a new one.</p>'
        .'<p>After that, sign in at <a href="{{sign_in_url}}">{{sign_in_url}}</a> with this email address, {{email}}.</p>'
        .'<p style="color:#64748b;font-size:13px;">If you were not expecting this email, you can ignore it.</p>';

    private const OLD_PLACEHOLDERS = ['member_name', 'club_name', 'invite_url', 'expiry_days'];

    public const NEW_PLACEHOLDERS = ['member_name', 'club_name', 'invite_url', 'expiry_days', 'inviter_name', 'role_name', 'what_you_can_do', 'email', 'sign_in_url'];

    public function up(): void
    {
        $this->swap(self::OLD_BODY, self::NEW_BODY, self::NEW_PLACEHOLDERS);
    }

    public function down(): void
    {
        $this->swap(self::NEW_BODY, self::OLD_BODY, self::OLD_PLACEHOLDERS);
    }

    /**
     * @param  list<string>  $placeholders
     */
    private function swap(string $from, string $to, array $placeholders): void
    {
        DB::table('default_email_templates')
            ->where('template_key', 'account_invitation')
            ->where('body_html', $from)
            ->update(['body_html' => $to, 'available_placeholders' => json_encode($placeholders)]);
    }
};
