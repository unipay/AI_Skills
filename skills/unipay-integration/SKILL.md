---
name: unipay-integration
description: Integrate UniPAY (unipay.com, Georgia) card payments into a merchant's backend with the UniPAY API v3 - authentication, creating an order and redirecting to the hosted payment page, receiving and verifying callbacks, checking order status, refunds, preauthorization, saved cards and subscriptions. Use when the user mentions UniPAY, apiv2.unipay.com, business.unipay.com or docs.unipay.com, or wants to accept Visa, Mastercard, Amex, Apple Pay or Google Pay through UniPAY, or is debugging an existing UniPAY integration.
---

# Integrating UniPAY payments

UniPAY is a Georgian payment provider. A merchant creates an **order** through the API,
sends the payer's browser to UniPAY's hosted payment page, and learns the outcome from a
**callback** that UniPAY sends to the merchant's server. Official docs: https://docs.unipay.com

```
your server ──POST /v3/auth──────────────► UniPAY      (token, 30 min)
your server ──POST /v3/custom/order/create► UniPAY      → data.Checkout (payment page URL)
payer's browser ──► Checkout URL ──► bank / 3-D Secure ──► your SuccessRedirectUrl / CancelRedirectUrl
UniPAY ──POST CallBackUrl?MerchantOrderID=…&Status=3&Hash=…──► your server   ← the source of truth
```

## Rules that are easy to get wrong

1. **Server-to-server only.** The API sends no CORS headers, and the secret key must never
   reach a browser or mobile app.
2. **Branch on `errorcode`, not the HTTP status.** Order endpoints answer HTTP 200 with
   `{"errorcode":0,"message":"OK","data":{…}}` on success *and* `{"errorcode":109,"message":"…"}`
   on failure, served as `text/html`. Always parse the body as JSON and check `errorcode === 0`.
3. **URLs are base64-encoded.** `SuccessRedirectUrl`, `CancelRedirectUrl`, `CallBackUrl` (and
   `Mlogo`) are base64-decoded by UniPAY. Send `"Base64": 1` only if you want to send them plain.
4. **Base64-encode `OrderName` and `OrderDescription` too.** UniPAY decodes them whenever the
   text happens to be valid base64, so plain text like `Book` arrives garbled.
5. **Amounts are in major units**: `"25.50"` means 25.50 GEL. Send 2 decimals as a string;
   extra decimals are cut, not rounded. Each project has min/max limits (default 0.50–1500).
6. **The redirect is not proof of payment.** UniPAY adds no parameters to your redirect URLs and
   can send the payer to `SuccessRedirectUrl` for a declined payment. Only the verified callback
   (or `get-details`) says an order is paid. Put your own order reference in the redirect URLs.
7. **Callback data arrives in the query string**, not the body (the body is `[]`). Verify `Hash`
   before trusting anything (see below).
8. **Use a new, unique `MerchantOrderID` for every payment attempt.** Once one order with a
   given `MerchantOrderID` has succeeded, UniPAY stops sending callbacks for other orders that
   reuse it.
9. **Orders expire fast.** An order that isn't paid within about 10 minutes becomes INCOMPLETE
   (23), and no callback is sent for it. Create a fresh order per checkout; don't store and reuse
   `Checkout` URLs.
10. **No idempotency on orders.** Retrying `order/create` creates a second order.

## Integration steps (API v3)

**Credentials.** In the business portal (https://business.unipay.com), on a verified business
with a project, open the project's API key settings. Each project has two credential pairs:
a *sandbox* merchant ID ending in `0` and a *production* one ending in `1`, each with a secret
key (a UUID). Make sure the API is switched on for the project. Store them as
`UNIPAY_MERCHANT_ID` / `UNIPAY_SECRET_KEY` environment variables.

**Base URL:** `https://apiv2.unipay.com`. Send `Accept: application/json` on every call.

**1. Get a token.** `POST /v3/auth` with `{"merchant_id":"…","api_key":"<secret key>"}` returns
`{"auth_token":"…","token_type":"Bearer","expires_in":1800}`. Cache it, send
`Authorization: Bearer <auth_token>`, and fetch a new one shortly before 30 minutes or on HTTP 401.
There is no refresh endpoint. Details: [references/authentication.md](references/authentication.md).

**2. Create the order.** `POST /v3/{alias}/order/create`. Use the alias **`custom`**: callbacks
then go to the `CallBackUrl` you send with each order (it is required for `custom`). The alias
`api` (used in the official docs) instead sends callbacks to the URL saved in the portal and
ignores `CallBackUrl`.

```json
{
  "MerchantOrderID": "ORD-1001-a1",
  "OrderPrice": "25.50",
  "OrderCurrency": "GEL",
  "OrderName": "T3JkZXIgIzEwMDE=",
  "OrderDescription": "MiBpdGVtcw==",
  "SuccessRedirectUrl": "<base64 of https://shop.example/checkout/done?order=ORD-1001>",
  "CancelRedirectUrl": "<base64 of https://shop.example/checkout/failed?order=ORD-1001>",
  "CallBackUrl": "<base64 of https://shop.example/webhooks/unipay>",
  "Language": "en",
  "MerchantUser": "customer-42",
  "MerchantUserEmail": "buyer@example.com"
}
```

Success: `{"errorcode":0,"message":"OK","data":{"Checkout":"https://…","UnipayOrderHashID":"MP…","UnipayOrderID":987654}}`.
Save `UnipayOrderHashID` (the order hash, used by every later call) next to your order, then
redirect the payer to `data.Checkout`. Every field, error code and option:
[references/create-order.md](references/create-order.md).

**3. Handle the callback.** UniPAY sends `POST <CallBackUrl>?MerchantOrderID=…&Status=…&Hash=…&Amount=…&UnipayOrderID=…&ErrorCode=…&ErrorMessage=…&Reason=…&Version=2`.

```
expected = lowercase hex sha256( UnipayOrderID + "|" + MerchantOrderID + "|" + Status + "|" + secret_key )
```

`UnipayOrderID` in the callback is the order **hash** (`MP…`), and `secret_key` is the
**production** secret key. Compare with a constant-time comparison. Then:

- `Status=3`: paid. `Amount` is not covered by the hash, so check it against your own order.
- `Status=13`, `22` or `-1`: failed, timed out at the bank, or cancelled.
- `Status=5` or `4`: a refund or reversal of this order settled.

Make the handler idempotent (one order gets several callbacks, and each is retried up to 3 times
about 10 s apart), and answer any 2xx quickly. The body is ignored. Full payload and status
list: [references/callbacks.md](references/callbacks.md).

**4. Check status when needed.** `GET /v3/{alias}/order/get-details/{UnipayOrderHashID}` returns
`status` (3 = paid; 1 = approved, still settling; 2 = preauth on hold), `amount` (net of
commission) and `commission`. Use it to reconcile orders whose callback never came; abandoned
orders send none. See [references/statuses-and-errors.md](references/statuses-and-errors.md).

**5. Refund.** `POST /v3/{alias}/order/refund` with `{"OrderHashID":"MP…","Amount":"10.00","Reason":"Customer request"}`.
`Amount` is always required, even for a full refund. Refunds are paid from the merchant's UniPAY
balance and run one at a time per project. See [references/refunds.md](references/refunds.md).

## More features

| Need | Read |
|---|---|
| Authorize now, capture later (`OrderType: "preauth"`, then `/order/confirm`) | [references/preauth.md](references/preauth.md) |
| Save a card (`InApp: 1`), charge it later (`RegularPaymentID`), recurring subscriptions | [references/saved-cards-and-subscriptions.md](references/saved-cards-and-subscriptions.md) |
| Maintain an older integration on `/{alias}/checkout/v1/...` with a `Hash` signature | [references/v1-legacy.md](references/v1-legacy.md) |
| Working client code (token cache, create order, callback check, refund) | [examples/php](examples/php/unipay.php), [examples/node](examples/node/unipay.mjs), [examples/python](examples/python/unipay.py) |

Transfers, currency exchange, balance and rates (`/v3/transfer`, `/v3/exchange`, `/v3/balance`,
`/v3/rates`) are partner features that UniPAY enables per merchant. They are not covered here.

## Testing

- **Sandbox credentials** (merchant ID ending in `0`) never create a real order. `order/create`
  runs all request validation and returns random numbers plus a test URL, and no callback
  follows. Use them to check authentication and request format.
- **End to end** needs production credentials. Pay a small real amount (at least the project
  minimum, 0.50 GEL by default), check the callback, then refund it.
- Callbacks must reach a public URL (`localhost` is refused). Use a tunnel such as ngrok while
  developing.
- `GET /v3/info/status-list` and `GET /v3/info/error-list` need no auth and list the codes.

## When something fails

| Symptom | Likely cause |
|---|---|
| HTTP 400 `DATA_IS_NOT_CORRECT` from `/v3/auth` | Wrong merchant ID/key pair, or a key regenerated in the portal that UniPAY hasn't synced yet. Contact UniPAY support. |
| HTTP 403 `Invalid ability provided.` | The credentials aren't enabled for that API area (subscriptions, for example). Ask UniPAY to enable it. |
| HTTP 500 with `errorcode` 101 | The project or its API is switched off in the portal. |
| `errorcode` 111 | `SuccessRedirectUrl` or `CancelRedirectUrl` is missing or not base64. |
| `errorcode` 120 | `CallBackUrl` is missing (required with the `custom` alias). |
| `errorcode` 108 / 109 | Amount below the project minimum or above the maximum. |
| `errorcode` 119 | Unknown `{alias}` in the URL. |
| Callback `Hash` never matches | Hashing the numeric `UnipayOrderID` from create instead of the `MP…` hash, reading the body instead of the query string, or using the sandbox key. |
| No callback at all | Sandbox credentials, an unpaid or abandoned order, a reused `MerchantOrderID`, or a preauth on hold (none is sent for HOLD). |
