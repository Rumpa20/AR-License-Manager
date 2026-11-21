/*
 * AR License Manager - Android / Java SDK example (v1.0).
 * Copyright (c) ARNIT CREATION. All rights reserved.
 *
 * Plain Java 8+, zero third-party dependencies:
 *  - HTTP via HttpURLConnection (works on Android without extra libraries)
 *  - Minimal built-in JSON encoder/decoder (no org.json needed)
 *  - Ed25519 response signature verification via java.security ("Ed25519",
 *    available on JDK 15+). On Android, where the JDK Ed25519 provider is
 *    usually absent, register a Tink-backed verifier with
 *    {@link #setVerifier(Verifier)} - see sdk/android/README.md.
 *
 * SECURITY MODEL - READ BEFORE SHIPPING:
 *
 *  1. NEVER embed your API client id + secret inside an APK. Anyone can
 *     decompile an APK and extract embedded strings. Android apps must talk
 *     to YOUR OWN backend server, which holds the secret and calls the
 *     license server on the app's behalf.
 *  2. Client-side license checks can always be bypassed by a determined
 *     attacker (decompilation, patching, proxying). Keep sensitive business
 *     logic, protected content and paid functionality SERVER-SIDE. Use the
 *     license check as a convenience gate, never as the only barrier.
 *  3. Responses are signed with Ed25519. This client verifies signatures
 *     FAIL-CLOSED: if no verifier is registered or the signature is missing
 *     or stale, {@link #assertVerified} throws. This class never silently
 *     downgrades to trusting unsigned responses. You may explicitly set
 *     {@link #allowUnsigned = true} for local development, at your own risk.
 */
import java.io.ByteArrayOutputStream;
import java.io.IOException;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.ArrayList;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

public final class ARLMClient {

    /** Implements Ed25519 verification. Provide with Tink on Android. */
    public interface Verifier {
        /** @return true when {@code signature} is a valid Ed25519 signature of msg with publicKey. */
        boolean verify(byte[] publicKey, byte[] message, byte[] signature);
    }

    private static final long CLOCK_SKEW_SECONDS = 300;

    private final String serverUrl;
    private final String clientId;
    private final String secret;
    private final String publicKeyB64url;
    private final int timeoutMs;
    private Verifier verifier;
    /** Only for local development. Must stay false in production builds. */
    public boolean allowUnsigned = false;

    public ARLMClient(String serverUrl, String clientId, String secret, String publicKeyB64url, int timeoutMs) {
        this.serverUrl = trimSlash(serverUrl);
        this.clientId = clientId == null ? "" : clientId;
        this.secret = secret == null ? "" : secret;
        this.publicKeyB64url = publicKeyB64url == null ? "" : publicKeyB64url;
        this.timeoutMs = timeoutMs;
    }

    public synchronized void setVerifier(Verifier v) {
        this.verifier = v;
    }

    /* ------------------------------------------------------------------ */
    /* API methods                                                         */
    /* ------------------------------------------------------------------ */

    /** POST /activate - consumes one activation slot for domain. */
    public Map<String, Object> activate(String licenseKey, String productId, String domain) throws IOException, HttpException {
        return post("activate", licenseKey, productId, domain);
    }

    /** POST /validate - re-verifies an activated domain; consumes nothing. */
    public Map<String, Object> validate(String licenseKey, String productId, String domain) throws IOException, HttpException {
        return post("validate", licenseKey, productId, domain);
    }

    /** POST /deactivate - frees the activation slot for domain. */
    public Map<String, Object> deactivate(String licenseKey, String productId, String domain) throws IOException, HttpException {
        return post("deactivate", licenseKey, productId, domain);
    }

    /** POST /check - status/expiry probe; domain optional (pass null). */
    public Map<String, Object> check(String licenseKey, String productId, String domain) throws IOException, HttpException {
        return post("check", licenseKey, productId, domain);
    }

    /** GET /products - lists active products (requires id + secret). */
    public Map<String, Object> products() throws IOException, HttpException {
        HttpURLConnection c = open("GET", serverUrl + "/api/v1/products", null);
        return readResponse(c);
    }

    /**
     * Throws when the reply fails signature or freshness verification.
     * Fail-closed: unsigned, unverifiable or stale replies never pass.
     */
    public void assertVerified(Map<String, Object> payload) throws SecurityException {
        long ts = longValue(payload.get("timestamp"));
        if (allowUnsigned) {
            return; // explicitly waived - your responsibility (dev only)
        }
        if (verifier == null || publicKeyB64url.isEmpty()) {
            throw new SecurityException(
                "ARLM: no Ed25519 verifier registered - refusing unsigned license data. "
                + "Register one with setVerifier() (Tink on Android) or explicitly set allowUnsigned=true (dev only).");
        }
        String sig = str(payload.get("signature"));
        if (sig.isEmpty()) {
            throw new SecurityException("ARLM: response carries no signature.");
        }
        boolean ok;
        try {
            ok = verifier.verify(base64UrlDecode(publicKeyB64url), canonical(payload), base64UrlDecode(sig));
        } catch (Exception e) {
            throw new SecurityException("ARLM: signature verification error: " + e.getMessage());
        }
        if (!ok) {
            throw new SecurityException("ARLM: license response signature verification FAILED.");
        }
        if (Math.abs(System.currentTimeMillis() / 1000L - ts) > CLOCK_SKEW_SECONDS) {
            throw new SecurityException("ARLM: license response timestamp is stale.");
        }
    }

    /** Builds the exact canonical string the server signs (LF-joined). */
    public static byte[] canonical(Map<String, Object> p) {
        return String.join("\n",
                str(p.get("product_id")),
                str(p.get("domain")),
                str(p.get("status")),
                str(p.get("expires_at")),
                String.valueOf(longValue(p.get("activation_limit"))),
                String.valueOf(longValue(p.get("current_activations"))),
                String.valueOf(longValue(p.get("timestamp"))),
                str(p.get("request_id"))
        ).getBytes(java.nio.charset.StandardCharsets.UTF_8);
    }

    /* ------------------------------------------------------------------ */
    /* HTTP layer                                                          */
    /* ------------------------------------------------------------------ */

    private Map<String, Object> post(String endpoint, String licenseKey, String productId, String domain)
            throws IOException, HttpException {
        StringBuilder json = new StringBuilder("{");
        addUserAgentField(json, true);
        json.append("\"license_key\":").append(jsonString(licenseKey));
        json.append(",\"product_id\":").append(jsonString(productId));
        if (domain != null && !domain.isEmpty()) {
            json.append(",\"domain\":").append(jsonString(domain));
        }
        json.append(",\"timestamp\":").append(System.currentTimeMillis() / 1000L);
        json.append(",\"request_id\":").append(jsonString(newRequestId()));
        json.append("}");
        HttpURLConnection c = open("POST", serverUrl + "/api/v1/" + endpoint, json.toString());
        return readResponse(c);
    }

    private HttpURLConnection open(String method, String url, String jsonBody) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
        c.setConnectTimeout(timeoutMs);
        c.setReadTimeout(timeoutMs);
        c.setRequestProperty("Accept", "application/json");
        c.setRequestProperty("X-ARLM-Client-Id", clientId);
        c.setRequestProperty("X-ARLM-Secret", secret);
        c.setRequestProperty("User-Agent", "ARLM-Android-SDK/1.0.0");
        if (jsonBody != null) {
            c.setRequestMethod("POST");
            c.setDoOutput(true);
            c.setFixedLengthStreamingMode(jsonBody.getBytes(java.nio.charset.StandardCharsets.UTF_8).length);
            c.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            try (java.io.OutputStream os = c.getOutputStream()) {
                os.write(jsonBody.getBytes(java.nio.charset.StandardCharsets.UTF_8));
            }
        } else {
            c.setRequestMethod(method);
        }
        return c;
    }

    private Map<String, Object> readResponse(HttpURLConnection c) throws IOException, HttpException {
        int code = c.getResponseCode();
        java.io.InputStream in = code >= 400 ? c.getErrorStream() : c.getInputStream();
        ByteArrayOutputStream buf = new ByteArrayOutputStream();
        if (in != null) {
            byte[] tmp = new byte[4096];
            int n;
            while ((n = in.read(tmp)) != -1) { buf.write(tmp, 0, n); }
            in.close();
        }
        String raw = new String(buf.toByteArray(), java.nio.charset.StandardCharsets.UTF_8);
        c.disconnect();
        Object parsed;
        try {
            parsed = new Json(raw).parse();
        } catch (RuntimeException e) {
            throw new HttpException(code, "ARLM: non-JSON response (HTTP " + code + ").");
        }
        if (!(parsed instanceof Map)) {
            throw new HttpException(code, "ARLM: unexpected JSON response shape (HTTP " + code + ").");
        }
        @SuppressWarnings("unchecked")
        Map<String, Object> data = (Map<String, Object>) parsed;
        data.put("_http_status", code);
        return data;
    }

    /** Thrown for HTTP-level failures; carries the status code. */
    public static class HttpException extends Exception {
        public final int statusCode;
        public HttpException(int statusCode, String message) { super(message); this.statusCode = statusCode; }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** Use OkHttp / SecureRandom on your side if you prefer richer ids. */
    private static String newRequestId() {
        byte[] b = new byte[16];
        new java.security.SecureRandom().nextBytes(b);
        StringBuilder sb = new StringBuilder(32);
        for (byte x : b) { sb.append(String.format("%02x", x)); }
        return "java-" + sb;
    }

    private static void addUserAgentField(StringBuilder json, boolean first) {
        if (first) {
            json.append("\"sdk\":\"ARLM-Android-SDK/1.0.0\",");
        }
    }

    private static String trimSlash(String s) {
        String t = s == null ? "" : s.trim();
        while (t.endsWith("/")) { t = t.substring(0, t.length() - 1); }
        return t;
    }

    private static String str(Object o) { return o == null ? "" : String.valueOf(o); }

    private static long longValue(Object o) {
        if (o instanceof Number) { return ((Number) o).longValue(); }
        try { return o == null ? 0L : Long.parseLong(String.valueOf(o)); }
        catch (NumberFormatException e) { return 0L; }
    }

    private static byte[] base64UrlDecode(String s) {
        String t = s.replace('-', '+').replace('_', '/');
        int pad = (4 - t.length() % 4) % 4;
        for (int i = 0; i < pad; i++) { t += "="; }
        return Base64.getDecoder().decode(t);
    }

    private static StringBuilder jsonString(String value) {
        StringBuilder sb = new StringBuilder("\"");
        for (int i = 0; i < value.length(); i++) {
            char ch = value.charAt(i);
            switch (ch) {
                case '"':  sb.append("\\\""); break;
                case '\\': sb.append("\\\\"); break;
                case '\b': sb.append("\\b"); break;
                case '\f': sb.append("\\f"); break;
                case '\n': sb.append("\\n"); break;
                case '\r': sb.append("\\r"); break;
                case '\t': sb.append("\\t"); break;
                default:
                    if (ch < 0x20) {
                        sb.append(String.format("\\u%04x", (int) ch));
                    } else {
                        sb.append(ch);
                    }
            }
        }
        return sb.append('"');
    }

    /* ------------------------------------------------------------------ */
    /* Minimal JSON parser (RFC 8259 subset, sufficient for API payloads)  */
    /* ------------------------------------------------------------------ */

    static final class Json {
        private final String src;
        private int pos;

        Json(String src) { this.src = src; }

        Object parse() {
            Object v = value();
            skipWs();
            if (pos != src.length()) { throw new IllegalStateException("Trailing JSON content at " + pos); }
            return v;
        }

        private Object value() {
            skipWs();
            char c = peek();
            switch (c) {
                case '{': return object();
                case '[': return array();
                case '"': return string();
                case 't': expect("true");  return Boolean.TRUE;
                case 'f': expect("false"); return Boolean.FALSE;
                case 'n': expect("null");  return null;
                default:  return number();
            }
        }

        private Map<String, Object> object() {
            Map<String, Object> map = new LinkedHashMap<>();
            pos++; // {
            skipWs();
            if (peek() == '}') { pos++; return map; }
            while (true) {
                skipWs();
                String key = string();
                skipWs();
                if (peek() != ':') { throw new IllegalStateException("Expected ':' at " + pos); }
                pos++;
                map.put(key, value());
                skipWs();
                char c = peek();
                if (c == ',') { pos++; }
                else if (c == '}') { pos++; return map; }
                else { throw new IllegalStateException("Expected ',' or '}' at " + pos); }
            }
        }

        private List<Object> array() {
            List<Object> list = new ArrayList<>();
            pos++; // [
            skipWs();
            if (peek() == ']') { pos++; return list; }
            while (true) {
                list.add(value());
                skipWs();
                char c = peek();
                if (c == ',') { pos++; }
                else if (c == ']') { pos++; return list; }
                else { throw new IllegalStateException("Expected ',' or ']' at " + pos); }
            }
        }

        private String string() {
            if (peek() != '"') { throw new IllegalStateException("Expected '\"' at " + pos); }
            pos++;
            StringBuilder sb = new StringBuilder();
            while (true) {
                char ch = src.charAt(pos++);
                if (ch == '"') { return sb.toString(); }
                if (ch == '\\') {
                    char e = src.charAt(pos++);
                    switch (e) {
                        case '"':  sb.append('"');  break;
                        case '\\': sb.append('\\'); break;
                        case '/':  sb.append('/');  break;
                        case 'b':  sb.append('\b'); break;
                        case 'f':  sb.append('\f'); break;
                        case 'n':  sb.append('\n'); break;
                        case 'r':  sb.append('\r'); break;
                        case 't':  sb.append('\t'); break;
                        case 'u':
                            sb.append((char) Integer.parseInt(src.substring(pos, pos + 4), 16));
                            pos += 4;
                            break;
                        default: throw new IllegalStateException("Bad escape \\" + e);
                    }
                } else {
                    sb.append(ch);
                }
            }
        }

        private Object number() {
            int start = pos;
            while (pos < src.length() && "-+.eE0123456789".indexOf(src.charAt(pos)) >= 0) { pos++; }
            String num = src.substring(start, pos);
            if (num.indexOf('.') < 0 && num.indexOf('e') < 0 && num.indexOf('E') < 0) {
                return Long.parseLong(num);
            }
            return Double.parseDouble(num);
        }

        private void expect(String word) {
            if (!src.startsWith(word, pos)) { throw new IllegalStateException("Bad literal at " + pos); }
            pos += word.length();
        }

        private void skipWs() {
            while (pos < src.length() && Character.isWhitespace(src.charAt(pos))) { pos++; }
        }

        private char peek() {
            if (pos >= src.length()) { throw new IllegalStateException("Unexpected end of JSON"); }
            return src.charAt(pos);
        }
    }
}
