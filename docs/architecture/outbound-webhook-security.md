# Outbound Webhook Security (Stage 1.1-D)

`outbound_webhook` is a reliable Action handler. It never advances the workflow directly.

## Capability

Publish definitions that use `action_key: outbound_webhook` only when the `outbound_webhook` runtime capability is enabled.

## Idempotency header

Every request includes:

```http
X-DBFlow-Idempotency-Key: instance:{id}:node:{key}:visit:{n}
```

Rules:

- Value is the logical Action execution key.
- Retries reuse the same value.
- A new legitimate node visit allocates a new visit sequence and key.
- Workflow custom headers cannot set or override this header.
- Downstream systems should deduplicate using this header.

## HMAC-SHA256 signing (optional)

Configure `payload.signing_secret_key` as a Secret Resolver reference.

Headers:

- `X-DBFlow-Timestamp` — UTC unix seconds
- `X-DBFlow-Idempotency-Key` — logical execution key
- `X-DBFlow-Signature` — hex HMAC-SHA256

### Canonical payload

UTF-8, LF-separated:

```text
{timestamp}
{idempotency_key}
{METHOD}
{path_and_query}
{sha256_hex_of_raw_body}
```

`path_and_query` is the URL path beginning with `/`, plus `?query` when present (no fragment).

### Signature

```text
X-DBFlow-Signature = hex(HMAC-SHA256(canonical_payload, signing_secret))
```

### Verification algorithm

1. Read timestamp, idempotency key, and signature headers.
2. Rebuild the canonical payload from the received method, URL path/query, and raw body.
3. Compute HMAC-SHA256 with the shared secret.
4. Compare with `hash_equals()`.
5. Optionally reject stale timestamps using host policy.

Retries may use a new timestamp and therefore a new signature, but keep the same idempotency key.

## Redirect policy

Redirects are **disabled by default** (`dbflow.webhook.follow_redirects=false`).

When globally enabled:

- enforce `max_redirects`;
- re-run SSRF/URL validation on every `Location` target;
- reject HTTPS→HTTP downgrade;
- reject private/loopback/metadata/reserved destinations;
- strip Authorization, Cookie, and signature headers when the host changes;
- regenerate signatures for the redirected request when signing is enabled.

Workflow definitions cannot disable global redirect protection.

## Header restrictions

Rejected custom headers include Host, Content-Length, Transfer-Encoding, Connection, Proxy-*, Cookie, Set-Cookie, and all `X-DBFlow-*` transport headers.

Also rejected: CR/LF, invalid names, case-insensitive duplicates, excessive count/length.

`Authorization` is allowed only when produced from a `{{ secret.* }}` template.

## Secrets

- Definitions store references only.
- Secrets resolve only during execution.
- Secrets never appear in execution snapshots, attempts, logs, diagnostics, or event payloads.
- Redaction failure drops unsafe payload data instead of storing raw values.

## Exactly-once honesty

DBFlow guarantees:

- one logical Action execution identity;
- idempotent retries of that identity;
- workflow progression at most once.

DBFlow does **not** guarantee that an external endpoint receives the HTTP request only once after an unknown worker outcome. Downstream systems must deduplicate with `X-DBFlow-Idempotency-Key`.
