# Legacy API v1 (`/{alias}/checkout/v1/...`)

New integrations should use v3. Use this page to maintain an existing integration, or to
recognize one: v1 URLs contain `/checkout/v1/`, and requests carry a `Hash` field or HTTP Basic
auth instead of a Bearer token. Request fields, responses, error codes and callbacks are the
**same as v3**. Only the URL and the authentication differ.

| Action | v1 | v3 equivalent |
|---|---|---|
| Create order | `POST /{alias}/checkout/v1/createorder` | `POST /v3/{alias}/order/create` |
| Refund | `POST /{alias}/checkout/v1/refund` | `POST /v3/{alias}/order/refund` |
| Capture preauth | `POST /{alias}/checkout/v1/confirm` | `POST /v3/{alias}/order/confirm` |
| Card details | `POST /{alias}/checkout/v1/get-card` | `POST /v3/card/get-details` |
| Subscription cancel / update / info | `POST /{alias}/checkout/v1/subscription-cancel` / `-update` / `-info` | `POST /v3/subscription/cancel` / `update` / `info` |
| Order status | none | `GET /v3/{alias}/order/get-details/{hash}` |
| Status / error lists | `GET /{alias}/checkout/v1/status-list`, `/error-list` | `GET /v3/info/status-list`, `/error-list` |

Same host: `https://apiv2.unipay.com`.

## Signing a v1 request

Every v1 request is signed with the secret key of the credential pair named by `MerchantID`:

```
values = [secret_key]
for each top-level field of the request body, in the order it is sent:
    skip it if the key is "Hash", "Items" or "lang", or if the value is an array or object
    otherwise append the value
Hash = lowercase_hex( sha256( join("|", values) ) )
```

Then either:

- put `"MerchantID": "<merchant id>"` and `"Hash": "<hash>"` in the body (`MerchantID` is then
  one of the hashed values, in its position), or
- send `Authorization: Basic base64("<merchant id>:<hash>")` and leave `MerchantID` out of
  the body.

Rules that make or break the signature:

- **Send every scalar as a JSON string** and hash exactly that string. UniPAY hashes numbers in
  PHP form, so a numeric `25.50` would be hashed as `25.5`.
- Values are trimmed of surrounding whitespace before hashing, and an empty string counts as
  an empty segment.
- The order of the values is the order of the fields in the JSON you send. Build the body as
  an ordered map and hash from the same map.
- A wrong signature returns error 401 `HTTP_AUTORIZATION_HASH_WRONG` with HTTP 500.

Worked example (dummy secret `00000000-0000-0000-0000-000000000000`):

```json
{"MerchantID":"12345004561","MerchantUser":"customer-42","MerchantUserEmail":"buyer@example.com",
 "MerchantOrderID":"ORD-1001","OrderPrice":"25.50","OrderCurrency":"GEL",
 "OrderName":"T3JkZXIgIzEwMDE=","OrderDescription":"MiBpdGVtcw==","Language":"en",
 "SuccessRedirectUrl":"aHR0cHM6Ly9zaG9wLmV4YW1wbGUvY2hlY2tvdXQvc3VjY2Vzcz9vcmRlcj1PUkQtMTAwMQ==",
 "CancelRedirectUrl":"aHR0cHM6Ly9zaG9wLmV4YW1wbGUvY2hlY2tvdXQvY2FuY2VsP29yZGVyPU9SRC0xMDAx",
 "CallBackUrl":"aHR0cHM6Ly9zaG9wLmV4YW1wbGUvd2ViaG9va3MvdW5pcGF5",
 "Items":[{"price":"25.50","quantity":1,"title":"T-shirt","description":"Blue, size M"}],
 "Hash":"38809c4a349981536def8e7fba1af756213414379721c19ace33656d18bd2b9d"}
```

String that was hashed (`Items` and `Hash` skipped):

```
00000000-0000-0000-0000-000000000000|12345004561|customer-42|buyer@example.com|ORD-1001|25.50|GEL|T3JkZXIgIzEwMDE=|MiBpdGVtcw==|en|aHR0cHM6Ly9zaG9wLmV4YW1wbGUvY2hlY2tvdXQvc3VjY2Vzcz9vcmRlcj1PUkQtMTAwMQ==|aHR0cHM6Ly9zaG9wLmV4YW1wbGUvY2hlY2tvdXQvY2FuY2VsP29yZGVyPU9SRC0xMDAx|aHR0cHM6Ly9zaG9wLmV4YW1wbGUvd2ViaG9va3MvdW5pcGF5
```

## Migrating v1 → v3

1. Keep the request bodies as they are and drop `MerchantID` and `Hash`.
2. Add a token step (`POST /v3/auth` with the same merchant ID and secret key, see
   [authentication.md](authentication.md)) and send `Authorization: Bearer <token>`.
3. Change the URLs as in the table above. The callback handler doesn't change.
4. Use `get-details` to reconcile orders that never got a callback.
