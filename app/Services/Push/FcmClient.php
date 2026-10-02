<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Http;

class FcmClient
{
    public function enabled(): bool
    {
        $key = config('services.fcm.server_key');

        return is_string($key) && $key !== '';
    }

    /**
     * @param  list<string>  $tokens
     * @param  array{title: string, body: string}  $notification
     * @param  array<string, string>  $data
     */
    public function send(array $tokens, array $notification, array $data = []): void
    {
        if (! $this->enabled() || $tokens === []) {
            return;
        }

        $key = (string) config('services.fcm.server_key');

        foreach (array_chunk(array_values($tokens), 1000) as $chunk) {
            Http::withHeaders([
                'Authorization' => 'key='.$key,
            ])
                ->connectTimeout(3)
                ->timeout(5)
                ->post('https://fcm.googleapis.com/fcm/send', [
                    'registration_ids' => $chunk,
                    'priority' => 'high',
                    'notification' => $notification,
                    'data' => $data,
                ])
                ->throw();
        }
    }
}
