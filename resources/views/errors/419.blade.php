@include('errors.show', [
    'status' => 419,
    'title' => $title ?? 'Page expired',
    'message' => $message ?? 'This page expired. Refresh and try again.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
])
