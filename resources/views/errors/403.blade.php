@include('errors.show', [
    'status' => 403,
    'title' => $title ?? 'You do not have access',
    'message' => $message ?? 'You do not have access to this area.',
    'homeUrl' => $homeUrl ?? url('/login'),
    'homeLabel' => $homeLabel ?? 'Log in',
    'signedIn' => $signedIn ?? false,
    'showLogout' => $showLogout ?? auth()->check(),
    'profileUrl' => $profileUrl ?? url('/profile'),
    'logoutUrl' => $logoutUrl ?? url('/logout'),
])
