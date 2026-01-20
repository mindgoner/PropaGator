# PropaGator

![PropaGator Logo](logo.png)

PropaGator is a Laravel package for recording inbound HTTP requests (webhooks, callbacks, integrations) and propagating them to other environments. It is designed for teams that need a reliable way to capture requests on a public server and replay them locally for development, debugging, and testing.

Key goals:
- Record full HTTP request snapshots (method, URL, headers, query, body, IP, UA).
- Normalize timestamps to UTC for cross-environment safety.
- Pull and replay requests on local environments that cannot receive webhooks directly.
- Optionally broadcast new requests via Pusher for near real-time sync.

## Requirements
- PHP 8.0+
- Laravel 9+
- Database connection configured

## Installation

Install via Composer:

```bash
composer require mindgoner/propagator
```

Publish the config and migration:

```bash
php artisan vendor:publish --tag=propagator-config
php artisan vendor:publish --tag=propagator-migrations
```

Run migrations:

```bash
php artisan migrate
```

## Configuration

All settings are environment-overridable. Add the following to your `.env` as needed:

```env
PROPAGATOR_TABLE_PREFIX=propagator_
PROPAGATOR_POLL_INTERVAL=1
PROPAGATOR_REMOTE_URL=https://your-public-app.test/propagator/pull
PROPAGATOR_KEY=your-basic-auth-user
PROPAGATOR_SECRET=your-basic-auth-pass

# Optional Pusher settings
PROPAGATOR_PUSHER_APP_ID=your-app-id
PROPAGATOR_PUSHER_APP_KEY=your-app-key
PROPAGATOR_PUSHER_APP_SECRET=your-app-secret
PROPAGATOR_PUSHER_APP_CLUSTER=mt1
```

Config reference (`config/propagator.php`):

- `table_prefix`: Prefix for the requests table.
- `poll_interval`: Polling frequency in seconds.
- `remote_url`: Fully-qualified pull endpoint on the public server.
- `basic_auth.key` / `basic_auth.secret`: Basic auth credentials for the pull endpoint.
- `pusher.*`: Pusher credentials. Broadcasting is enabled when all required values are present.
- `timezone`: Fixed to UTC internally.

## Database Schema

A publishable migration creates the request log table with the configured prefix. Each record stores:
- `requestId` (unique)
- `requestMethod`
- `requestUrl`
- `requestHeaders`
- `requestQueryParams`
- `requestBody`
- `requestIp`
- `requestUserAgent`
- `requestReceivedAt` (UTC, indexed)
- `created_at`, `updated_at`

## Recording Requests

Add `Propagator::record($request)` to any controller or middleware where you want to persist incoming requests.

Example controller usage:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Mindgoner\Propagator\Facades\Propagator;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        Propagator::record($request);

        return response()->json(['ok' => true]);
    }
}
```

The recorder stores full request context and timestamps using UTC. When the request is recorded locally (not replayed), a broadcast event is fired if Pusher is configured.

## Public Pull Endpoint

The package exposes a route that returns recorded requests newer than a `since` timestamp.

- Path: `/propagator/pull` (configurable via `PROPAGATOR_PULL_PATH`)
- Auth: HTTP Basic (`PROPAGATOR_KEY` / `PROPAGATOR_SECRET`)
- Query param: `since` (UTC timestamp)

Example request:

```bash
curl -u "$PROPAGATOR_KEY:$PROPAGATOR_SECRET" \
  "https://your-public-app.test/propagator/pull?since=2024-01-01T00:00:00Z"
```

Example response:

```json
[
  {
    "id": 1,
    "requestId": "f9fdb21f-5f50-4d0e-9d9f-c4f2b111ea4f",
    "requestMethod": "POST",
    "requestUrl": "https://your-public-app.test/webhooks/provider",
    "requestHeaders": {"content-type": ["application/json"]},
    "requestQueryParams": [],
    "requestBody": "{\"event\":\"paid\"}",
    "requestIp": "203.0.113.10",
    "requestUserAgent": "ProviderBot/1.0",
    "requestReceivedAt": "2024-01-01T00:00:05Z",
    "created_at": "2024-01-01T00:00:05Z",
    "updated_at": "2024-01-01T00:00:05Z"
  }
]
```

## Listening and Replaying Locally

Local environments should run:

```bash
php artisan propagator:listen
```

Behavior:
- Reads the latest local `requestReceivedAt` and pulls all newer records from the public server.
- If Pusher is configured, listens for live events on `propagator.requests`.
- If Pusher is not configured, polls the pull endpoint every `PROPAGATOR_POLL_INTERVAL` seconds.
- Every received record is re-recorded locally using `Propagator::record()`.
- Each record is replayed to the original `requestUrl` stored in the record.

Replay uses the stored HTTP method, headers, query params, and body. It also adds:

```
X-Propagator-Origin: remote
```

This prevents infinite propagation loops by suppressing outbound broadcast for replayed requests.

## Usage Examples

Manual recording in a controller:

```php
use Illuminate\Http\Request;
use Mindgoner\Propagator\Facades\Propagator;

Route::post('/webhook', function (Request $request) {
    Propagator::record($request);
    return response()->noContent();
});
```

Configure connection with public server:

```bash
PROPAGATOR_REMOTE_URL=https://public.example.com/propagator/pull \
PROPAGATOR_KEY=demo \
PROPAGATOR_SECRET=demo \
```

Start local listener (polling fallback):

```bash
php artisan propagator:listen
```

Enable Pusher live updates:

```env
PROPAGATOR_PUSHER_APP_ID=your-app-id
PROPAGATOR_PUSHER_APP_KEY=your-app-key
PROPAGATOR_PUSHER_APP_SECRET=your-app-secret
PROPAGATOR_PUSHER_APP_CLUSTER=mt1
```

## Pusher Notes

PropaGator broadcasts `request.recorded` on the public channel `propagator.requests`. The listener subscribes to that channel when Pusher is configured and the websocket client is available.

## Security Considerations

- Always set `PROPAGATOR_KEY` and `PROPAGATOR_SECRET` on public servers.
- Do not expose the pull endpoint without auth.
- Consider whitelisting IPs at your reverse proxy if possible.

## Troubleshooting

- **Pull returns 401**: Check `PROPAGATOR_KEY`/`PROPAGATOR_SECRET` on both sides.
- **No records pulled**: Verify `PROPAGATOR_REMOTE_URL` points to the public pull endpoint and timestamps are UTC.
- **No Pusher events**: Ensure all `PROPAGATOR_PUSHER_*` vars are set and the websocket client dependency is installed.

## License

MIT
