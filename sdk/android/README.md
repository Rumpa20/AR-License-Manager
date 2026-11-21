# AR License Manager — Android / Java SDK (v1.0)

`ARLMClient.java` is a **single-file, dependency-free** Java client (works with
plain Java 8+ and Android projects; no org.json, Gson or OkHttp required).

## What it does

| Method | Endpoint | Purpose |
|---|---|---|
| `activate(licenseKey, productId, domain)` | POST /activate | Registers the domain, consumes one activation slot |
| `validate(licenseKey, productId, domain)` | POST /validate | Re-verifies an activation (free) |
| `deactivate(licenseKey, productId, domain)` | POST /deactivate | Frees the slot |
| `check(licenseKey, productId, null)` | POST /check | Status / expiry probe |
| `products()` | GET /products | Lists active products |
| `assertVerified(payload)` | — | Fail-closed Ed25519 signature + freshness check |

Minimal usage (desktop Java or a JVM test):

```java
ARLMClient client = new ARLMClient(
    "https://arnitcreation.online/arlm",   // license server URL
    "YOUR_CLIENT_ID",                       // from License Server → API Clients
    "YOUR_CLIENT_SECRET",                   // SERVER-SIDE ONLY - see warning below
    "SERVER_ED25519_PUBLIC_KEY_B64URL",     // from the installer output
    15000);
Map<String, Object> res = client.activate("ARLM-XXXX-XXXX-XXXX-XXXX", "PRODUCT-001", "app.example.com");
if (Boolean.TRUE.equals(res.get("success"))) {
    client.assertVerified(res);             // fails closed on bad/stale signatures
    // licensed - proceed
}
```

## Ed25519 verification on Android

Desktop JDK 15+ ships the Ed25519 provider, but **Android usually does not**.
`ARLMClient` verifies signatures through the pluggable `Verifier` interface and
**refuses to trust unsigned replies** when no verifier is registered
(`assertVerified` throws `SecurityException`). Wire it to
[Tink](https://developers.google.com/tink) (recommended, works on all modern
Android API levels):

```java
// build.gradle: implementation 'com.google.crypto.tink:tink:1.12.0'
client.setVerifier((publicKey, message, signature) -> {
    try {
        Ed25519PublicKey key = Ed25519PublicKey.parseFrom(
            Ed25519PublicKey.newBuilder().setKeyValue(ByteString.copyFrom(publicKey)).build().toByteArray());
        // Simpler: store the full Tink keyset, or construct the key from raw bytes:
        return TinkEd25519Verify(publicKey).verify(message, signature); // see note below
    } catch (Exception e) {
        return false;
    }
});
```

Practical Tink pattern most teams use:

```java
// Create the Tink PublicKeyVerify once from the server key, then delegate:
com.google.crypto.tink.PublicKeyVerify tinkVerifier =
    com.google.crypto.tink.signature.SignatureConfig
        .getPrimitiveHandle(...)   // or: Registry.getPrimitive(publicKeysetHandle, PublicKeyVerify.class);
client.setVerifier((publicKey, message, signature) -> {
    try { tinkVerifier.verify(signature, message); return true; }
    catch (GeneralSecurityException e) { return false; }
});
```

Whichever library you choose, the rules are fixed by the wire format:

* Signature = **Ed25519 detached**, over the LF-joined canonical string
  `ARLMClient.canonical(payload)`: `product_id`, `domain`, `status`,
  `expires_at`, `activation_limit`, `current_activations`, `timestamp`,
  `request_id`.
* Keys are **base64url** encoded.
* Replies older than ±300 s must be rejected (the client does this for you).

## MANDATORY security rules for Android products

1. **Never embed `X-ARLM-Client-Id` + `X-ARLM-Secret` in the APK.** Every
   embedded string can be extracted from a decompiled APK; a leaked secret
   lets anyone activate, deactivate or burn other customers' licenses.
   Android apps should call *your own* backend (or an authenticated cloud
   function) which holds the client credentials and talks to the License
   Server server-to-server.
2. **Never ship the license server's Ed25519 *private* key anywhere**, in any
   app, SDK or document.
3. **Client-side checks are bypassable.** Determined attackers can patch DEX
   bytecode, swap the license check for `return true`, or proxy HTTPS. Use the
   license gate as UX, not as enforcement: keep paid logic, telemetry-gated
   features and protected content on your server, and use the signed
   response's `status`/`expires_at` server-side.
4. Use a **unique license key per customer/purchase** (Map the purchase to a
   license via the store webhook, see `docs/STORE-INTEGRATION.md`); shared
   keys forget who owns them.
5. Store the customer's key in Android `EncryptedSharedPreferences` /
   Keystore-backed storage, never in plain `SharedPreferences`, logs or
   Firebase Remote Config.

## Error handling

All non-2xx replies are returned as parsed JSON with `_http_status` set, and
contain `error_code` (machine) and `error` (human) plus `success: false`.
Network/socket errors throw `IOException`; HTTP-shape problems throw the
nested `ARLMClient.HttpException` (with `.statusCode`). Recommended retry
policy: exponential backoff on `IOException`, **5xx and network errors only**;
never retry a request with the same `request_id` because activation is
idempotent per request id (retries are, in fact, safe — the server replays the
stored response).

---
Copyright (c) ARNIT CREATION. All rights reserved.
