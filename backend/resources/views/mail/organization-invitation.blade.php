<x-mail::message>
# Join {{ $organizationName }} on AMAN

{{ $inviterName }} invited you to join **{{ $organizationName }}** as **{{ ucfirst($role) }}**.

<x-mail::button :url="$acceptanceUrl">
Accept invitation
</x-mail::button>

This invitation expires {{ $expiresAt }} and can only be used once. If you were not expecting it, you can ignore this email.

Thanks,<br>
The {{ config('app.name') }} team
</x-mail::message>
