@include('errors.show', [
    'status' => 503,
    'title' => $title ?? 'Temporarily unavailable',
    'message' => $message ?? 'MailDesk is temporarily unavailable. Try again shortly.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
])