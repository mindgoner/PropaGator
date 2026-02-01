![PropaGator Logo](logo.png)

PropaGator is a Laravel package for recording inbound HTTP requests (webhooks, callbacks, integrations) and propagating them to other environments. It is designed for teams that need a reliable way to capture requests on a public server and replay them locally for development, debugging, and testing.

Key goals:
- Record full HTTP request snapshots (method, path, headers, query, body, IP, UA).
- Normalize timestamps to UTC for cross-environment safety.
- Pull and replay requests on local environments that cannot receive webhooks directly.

## Requirements
- PHP 7.4+
- Laravel 8+
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
PROPAGATOR_LOCAL_URL=http://localhost
PROPAGATOR_KEY=your-basic-auth-user
PROPAGATOR_AUTH_SECRET=your-basic-auth-pass
PROPAGATOR_SECRET=shared-encryption-secret
```

Config reference (`config/propagator.php`):

- `table_prefix`: Prefix for the requests table.
- `poll_interval`: Polling frequency in seconds.
- `remote_url`: Fully-qualified pull endpoint on the public server.
- `local_base_url`: Base URL for replaying requests locally.
- `basic_auth.key` / `basic_auth.secret`: Basic auth credentials for the pull endpoint. If `PROPAGATOR_AUTH_SECRET` is not set, it falls back to `PROPAGATOR_SECRET`.
- `timezone`: Fixed to UTC internally.
- `shared_secret`: Shared encryption secret for `/propagator/pull` payloads.

## Database Schema

A publishable migration creates the request log table with the configured prefix. Each record stores:
- `id` (uuid, primary)
- `method`
- `path`
- `headers`
- `query_params`
- `body`
- `ip`
- `user_agent`
- `received_at` (UTC, indexed)
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

The recorder stores full request context and timestamps using UTC. Only the request path is stored, not the full URL.

## Public Pull Endpoint

The package exposes a route that returns recorded requests newer than a `since` timestamp, encrypted with `PROPAGATOR_SECRET`.

- Path: `/propagator/pull` (configurable via `PROPAGATOR_PULL_PATH`)
- Auth: HTTP Basic (`PROPAGATOR_KEY` / `PROPAGATOR_AUTH_SECRET`)
- Query param: `since` (UTC timestamp)

Example request:

```bash
curl -u "$PROPAGATOR_KEY:$PROPAGATOR_AUTH_SECRET" \
  "https://your-public-app.test/propagator/pull?since=2024-01-01T00:00:00Z"
```

Example response:

```json
{
  "success": true,
  "message": null,
  "content": "BASE64_ENCRYPTED_JSON"
}
```

`content` is the encrypted JSON array of request records (AES-256-CBC with HMAC-SHA256).

## Listening and Replaying Locally

Local environments should run:

```bash
php artisan propagator:listen
```

Behavior:
- Reads the latest local `receivedAt` and pulls all newer records from the public server.
- Polls the pull endpoint every `PROPAGATOR_POLL_INTERVAL` seconds.
- Every received record is re-recorded locally using `Propagator::record()`.
- Each record is replayed to `PROPAGATOR_LOCAL_URL` + stored `path`.

Replay uses the stored HTTP method, headers, query params, and body. It also adds:

```
X-Propagator-Origin: remote
```

You can use this header to detect replayed traffic on your local endpoint.

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
PROPAGATOR_LOCAL_URL=http://localhost \
PROPAGATOR_KEY=demo \
PROPAGATOR_AUTH_SECRET=demo \
PROPAGATOR_SECRET=shared-secret \
```

Start local listener:

```bash
php artisan propagator:listen
```

## Security Considerations

- Always set `PROPAGATOR_KEY` and `PROPAGATOR_AUTH_SECRET` on public servers.
- Always set the same `PROPAGATOR_SECRET` on both public and local environments.
- Do not expose the pull endpoint without auth.
- Pull payloads are encrypted and authenticated (AES-256-CBC + HMAC-SHA256).
- Consider whitelisting IPs at your reverse proxy if possible.

## Troubleshooting

- **Pull returns 401**: Check `PROPAGATOR_KEY`/`PROPAGATOR_AUTH_SECRET` on both sides.
- **No records pulled**: Verify `PROPAGATOR_REMOTE_URL` points to the public pull endpoint and timestamps are UTC.

## License

MIT
