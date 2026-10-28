@include('errors.show', [
    'status' => 404,
    'title' => $title ?? 'Page not found',
    'message' => $message ?? 'That page could not be found.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
])
