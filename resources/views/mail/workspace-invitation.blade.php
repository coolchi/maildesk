<x-mail::message>
# You're invited to {{ $organizationName }}

{{ $inviterName }} invited you to join **{{ $organizationName }}** on {{ config('app.name') }} as a **{{ $role }}**.

<x-mail::button :url="$acceptUrl">
Accept invitation
</x-mail::button>

This link expires{{ $expiresAt ? ' on '.$expiresAt : '' }}. If you weren't expecting this, you can ignore the email.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
