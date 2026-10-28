@include('errors.show', [
    'status' => 405,
    'title' => $title ?? 'Method not allowed',
    'message' => $message ?? 'That action is not available for this page. Go back and try again.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
])
