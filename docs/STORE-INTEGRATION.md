# AR License Manager v1.0 — Store Integration (Cashfree store)

Server-to-server integration between your existing PHP + MySQL store and
the AR License Manager server. The store is NOT modified beyond this one
notification call.

Copyright (c) ARNIT CREATION.

## Architecture

```
Customer pays -> Cashfree -> your store verifies payment SERVER-SIDE
                                   |
                                   | (paid confirmed, never a browser redirect)
                                   v
              arlm_notify_license_server($order)     [sdk/store-adapter/]
                                   |  JSON + HMAC-SHA256 headers
                                   v
   https://arnitcreation.online/arlm/webhooks/store/   [license server]
                                   |
                     license generated + emailed + order row fulfilled
```

## One-time setup

1. **License server**: Admin → Settings → Store Integration → **Rotate
   secret** → copy the shown value ONCE (a random secret already exists
   from installation; rotating replaces it).
2. **Store server**: set two environment variables (e.g. in an
   .htaccess-protected store config or your hosting panel):
   - `ARLM_SERVER_URL=https://arnitcreation.online/arlm`
   - `ARLM_STORE_SHARED_SECRET=<the secret copied in step 1>`
   Secrets must NOT live in git or in publicly served files.
3. Deploy `sdk/store-adapter/arlm-store-notify.php` into the store
   (single file, no dependencies, curl with stream fallback).

## Calling it after payment

Call the adapter ONLY after the Cashfree payment has been verified
server-side (Cashfree's own webhook/API verification inside the store -
never from a browser success redirect, which anyone can craft):

```php
require_once __DIR__ . '/arlm-store-notify.php';

$result = arlm_notify_license_server(array(
    'order_ref'   => $orderRef,        // your unique order reference
    'txn_id'      => $cashfreeTxnId,
    'product_uid' => 'PRODUCT-001',    // must exist and be active on the license server
    'amount'      => '499.00',
    'currency'    => 'INR',
    'customer'    => array('email' => $email, 'name' => $name),
    'license'     => array(            // all keys optional
        'type'             => 'annual',
        'activation_limit' => 1,
        'duration_days'    => 365,
        'notify_email'     => $email,
    ),
));

if (!$result['ok']) {
    error_log('ARLM notify failed: ' . $result['error'] . ' order=' . $orderRef);
    // queue for retry - do NOT mark delivery as sent
}
```

On success `$result['data']['license_key']` holds the plaintext key (shown
once over the wire) and `license_id`/`order_id`/`expires_at` are populated;
the customer also receives the license email from the license server. If
your store already emails a delivery, ignore the `license_key` field in
code - the license server can never re-display it later.

## Wire protocol (what the adapter does, for other-language ports)

- `POST {ARLM_SERVER_URL}/webhooks/store/`, Content-Type application/json.
- Headers: `X-ARLM-Store-Timestamp: <unix ts>` (±300 s accepted),
  `X-ARLM-Store-Signature: hex(hmac_sha256(rawJsonBody, sharedSecret))`.
- Body: the order array above plus `timestamp` and `store_name`.
- The secret is never sent; only its HMAC of the exact raw body bytes.

### Fulfillment guarantees (license server side)

- Signature + timestamp verified with constant-time compares; failures
  answered 401/400 and written to Security Logs.
- Fulfillment runs in a DB transaction with `SELECT ... FOR UPDATE` on
  `order_ref` (UNIQUE). A duplicate webhook returns the original result
  with `already_fulfilled: true` - exactly one license per order, ever.
- Orders already marked `failed`, `refunded` or `blocked` answer
  `409 ORDER_NOT_FULFILLABLE` and are never auto-fulfilled.
- Unknown/inactive `product_uid` → `422 PRODUCT_NOT_FOUND` (logged).
- Blocked customer email → `403 CUSTOMER_BLOCKED` (logged).
- Any internal error rolls the transaction back → `500 FULFILLMENT_FAILED`
  with nothing half-written.

### Safe retry policy for the store

Retry the notification (exponential backoff, capped) whenever the call
fails at network level or returns 5xx. Do not retry on 4xx other than
400/422 until you have fixed the cause; any 2xx response, including
`already_fulfilled`, means stop retrying.

## Order/customer records

- `orders` rows carry `order_ref` (unique), Cashfree `txn_id`, store name,
  amount, currency, lifecycle status, raw JSON payload (admin-server view
  only) and the generated `license_id`.
- Customers are looked up by email and created on first purchase
  (`source = external`); pass `customer.smf_member_id` to link an SMF forum
  account immediately.

## Cashfree checklist (store side, outside this project)

- Enable Cashfree webhooks to a store endpoint and verify them with
  Cashfree's signature scheme inside the store.
- Fulfillment to ARLM happens only from that verified path.
- Store the Cashfree order mapping so refunds can later be matched to
  `order_ref` and handled manually (ARLM marks such orders `refunded` in
  Admin → Orders; refunds never auto-revoke licenses).
