@include('errors.show', [
    'status' => 500,
    'title' => $title ?? 'Something went wrong',
    'message' => $message ?? 'Something went wrong. Try again in a moment.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
])
