# Create an order: `POST /v3/{alias}/order/create`

```http
POST https://apiv2.unipay.com/v3/custom/order/create
Authorization: Bearer <auth_token>
Content-Type: application/json
Accept: application/json
```

## The `{alias}` path segment

The alias tells UniPAY where to send callbacks for the order.

| Alias | Callback goes to | `CallBackUrl` field |
|---|---|---|
| `custom` | the order's own `CallBackUrl` | **required** (error 120 if empty) |
| `api` | the callback URL saved for the project in the business portal | ignored |

Use `custom` unless the merchant has deliberately configured a project-wide callback URL in the
portal. Other aliases belong to UniPAY's e-commerce plugins (Shopify, WooCommerce, OpenCart,
Tilda and others) and shouldn't be used for a custom integration. An unknown alias returns
error 119.

## Request fields

Field names are case-sensitive.

| Field | Required | Format and behaviour |
|---|---|---|
| `MerchantOrderID` | yes | Your reference, echoed in callbacks. Use a **new unique value per payment attempt** (for example `ORD-1001-a1`, `ORD-1001-a2` for a retry). After one order with this value succeeds, UniPAY stops sending callbacks for other orders that reuse it. |
| `OrderPrice` | yes* | Major units as a string with 2 decimals: `"25.50"` = 25.50 GEL. Extra decimals are truncated. Must be > 0 and within the project's min/max (default 0.50–1500). *Not needed with `InApp: 1` (card saving) or `SubscriptionPlanID`. |
| `OrderCurrency` | no | `GEL` (default), `USD` or `EUR`. Depending on project settings, a foreign-currency order may be converted to GEL. |
| `SuccessRedirectUrl` | yes | Base64 of the URL the payer returns to after a successful payment. |
| `CancelRedirectUrl` | yes | Base64 of the URL the payer returns to after a failure or cancel. |
| `CallBackUrl` | yes for `custom` | Base64 of your server endpoint for callbacks. Must be publicly reachable; `localhost` and `127.0.0.1` are refused. |
| `Base64` | no | Default `0`: the URL fields above (and `Mlogo`) are base64-decoded. Send `1` to pass them as URL-encoded plain text instead. |
| `OrderName` | no | Title shown on the payment page. **Base64-encode it**: UniPAY decodes the value whenever it is valid base64, so plain words like `Book` get garbled. |
| `OrderDescription` | no | Description on the payment page. Base64-encode it for the same reason. |
| `Language` | no | Payment page language: `ge` (Georgian, default) or `en`. Case-insensitive. |
| `MerchantUser` | no | Your customer identifier or name. |
| `MerchantUserEmail` | no | Customer email shown on the payment page. |
| `Items` | no | Line items shown on the payment page, display only (not summed or checked against `OrderPrice`). Each item needs `title` (string), `quantity` (integer), `price` (number) and a non-empty `description`. Optional: `currency` (`GEL`/`USD`/`EUR`), `attribute`. Error 113 if malformed. Omit it if you don't need it. |
| `Mlogo` | no | Base64 of a logo URL for the payment page (note the casing). Error 112 if not a valid URL. |
| `Mslogan` | no | Slogan on the payment page (note the casing). |
| `OrderType` | no | `"preauth"` to authorize only and capture later. See [preauth.md](preauth.md). |
| `InApp` | no | `1` to save the payer's card for later charges. See [saved-cards-and-subscriptions.md](saved-cards-and-subscriptions.md). |
| `RegularPaymentID` | no | A saved card token (`card_…`) to charge without the payer. Capital `P`. |
| `SubscriptionPlanID` | no | A plan's `plan_id` (UUID) to start a subscription; needs `RegularPaymentID`. |

Encoding example (any language: base64 of the UTF-8 string, standard alphabet with padding):

```
https://shop.example/checkout/done?order=ORD-1001  →  aHR0cHM6Ly9zaG9wLmV4YW1wbGUvY2hlY2tvdXQvZG9uZT9vcmRlcj1PUkQtMTAwMQ==
```

## Response

All outcomes come back as **HTTP 200** with a JSON body (sent as `text/html`, so parse it
regardless of the content type):

```json
{"errorcode": 0, "message": "OK", "data": {
  "Checkout": "https://<payment page host>/MP5005000012343A1B2C3D4E5F6",
  "UnipayOrderHashID": "MP5005000012343A1B2C3D4E5F6",
  "UnipayOrderID": 987654
}}
```

| Field | Use |
|---|---|
| `Checkout` | Redirect the payer's browser here. It may be UniPAY's payment page or the bank's page directly, so don't build or parse it. |
| `UnipayOrderHashID` | The order hash. Store it with your order: `get-details`, `refund`, `confirm` and callbacks all use it. |
| `UnipayOrderID` | Internal numeric ID. Not used by any other call, and **not** the value in callbacks. |

When charging a saved card (`RegularPaymentID`), `data` normally contains only
`UnipayOrderHashID` (no `Checkout`). See [saved-cards-and-subscriptions.md](saved-cards-and-subscriptions.md).

Error example: `{"errorcode": 109, "message": "MAX_AMOUNT_ERROR"}`.

## Errors

| Code | Meaning |
|---|---|
| 103 | `MerchantOrderID` missing |
| 104 | `OrderPrice` missing or not above 0 |
| 108 / 109 | `OrderPrice` below the project minimum / above the maximum |
| 111 | `SuccessRedirectUrl` or `CancelRedirectUrl` missing (or empty after base64 decoding) |
| 112 | `Mlogo` isn't a valid URL |
| 113 | `Items` malformed |
| 116 | saved card not found (`SubscriptionPlanID` without a valid `RegularPaymentID`) |
| 119 | unknown alias in the URL |
| 120 | `CallBackUrl` missing (alias `custom`) |
| 126 / 127 | currency conversion rate out of date / not configured |
| 130 | card saving or saved-card charges aren't enabled for the project |
| 132 | preauthorization isn't enabled for the project |
| 134 | subscription plan not found for this project |
| 504 | unknown `OrderCurrency` |

Other codes come from the bank. `GET /v3/info/error-list` lists the codes UniPAY knows about.

## After the order is created

1. Redirect the payer to `data.Checkout`.
2. The payer pays (possibly with 3-D Secure) and is sent back to `SuccessRedirectUrl` or
   `CancelRedirectUrl` exactly as you gave them. UniPAY appends nothing, so include your own
   order reference in those URLs.
3. Show a "processing" page on return, and decide the outcome from the callback (or
   `get-details`), not from which URL the payer landed on. The redirect can arrive before or
   after the callback, and some flows return the payer to the success URL even when the payment
   was declined.
4. An unpaid order becomes INCOMPLETE (23) within about 10 minutes, without a callback. If the
   payer wants to try again, create a new order with a new `MerchantOrderID`.
