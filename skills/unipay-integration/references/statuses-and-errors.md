# Order status, statuses and errors

## Check an order: `GET /v3/{alias}/order/get-details/{UnipayOrderHashID}`

```http
GET https://apiv2.unipay.com/v3/custom/order/get-details/MP5005000012343A1B2C3D4E5F6
Authorization: Bearer <auth_token>
Accept: application/json
```

```json
{"errorcode": 0, "message": "OK", "data": {
  "order": "MP5005000012343A1B2C3D4E5F6",
  "status": 3,
  "status_label": "COMMON.TRANSACTION_SUCCESS",
  "amount": "24.25",
  "commission": "1.25",
  "completed_at": "17-10-2026 14:03:11",
  "created_at": "17-10-2026 14:01:58"
}}
```

- Only orders of the calling project are found. An unknown hash returns
  `{"errorcode":135,"message":"ERROR_SUBSCRIPTION_CONTRACT_NOT_FOUND"}` (the name is misleading:
  it means "order not found").
- `amount` is the merchant's **net** amount after UniPAY's `commission`, filled in once the
  order succeeds.
- Dates are `DD-MM-YYYY HH:MM:SS` in Georgian time (Asia/Tbilisi). `completed_at` is `"N/A"`
  while the order isn't complete.
- Use it to reconcile orders with no callback, for example a job that checks orders still
  pending 15 minutes after creation.

## Statuses

| Code | Name | What to do |
|---|---|---|
| 1000 | Created | Payer hasn't finished. Pending. |
| 1001 | Processing | Pending. |
| 1 | Process | Approved by the bank, still settling. Re-check shortly; the `Status=3` callback has usually been sent already. |
| 2 | Hold | Preauthorized, waiting for you to capture (see [preauth.md](preauth.md)). |
| **3** | **Success** | **Paid.** Final. |
| 13 | Failed | Declined or failed. Final. |
| 22 | Incomplete (bank) | Payer reached the bank page and timed out. Final. |
| 23 | Incomplete | Abandoned or expired before paying. Final. |
| -1 | Canceled | Cancelled. Final. |
| 5 | Refunded | Fully refunded. |
| 19 | Partially refunded | Partly refunded; more can be refunded. |
| 4 | Reversed | Fully reversed (cancelled the same day). |
| 21 | Partially reversed | Partly reversed. |

`GET /v3/info/status-list` (no token needed) returns the statuses UniPAY shows to merchants.

## Response formats

| Situation | HTTP | Body |
|---|---|---|
| Order call succeeded | 200 | `{"errorcode":0,"message":"OK","data":{…}}` (`data` left out when empty) |
| Order call rejected (validation, business rule, bank) | **200** | `{"errorcode":<code>,"message":"<text or KEY>"}` |
| Field validation on `confirm` | 400 | `{"errorcode":400,"message":"…","errors":{"field":["…"]}}` |
| `/v3/auth` problems | 400 | `{"message":"DATA_IS_NOT_CORRECT"}` or `{"error":{"code":"parameter_invalid",…}}` |
| Token missing or expired | 401 | `{"errorcode":401,"message":"Not Autheticated"}` |
| API area not enabled | 403 | `{"message":"Invalid ability provided."}` |
| Project or API off, capture rule broken | 500 | `{"errorcode":<code>,"message":"…","errors":[]}` |

Robust client logic:

```
parse body as JSON (whatever the Content-Type)
if HTTP 401                     → get a new token, retry once
if body.errorcode === 0         → success, use body.data
else                            → failure: log body.errorcode + body.message (+ body.errors)
```

`message` may be a constant like `MAX_AMOUNT_ERROR` or a translated sentence (often Georgian).
Don't parse it; use the code.

## Error codes

| Code | Meaning |
|---|---|
| 101 | Merchant, project or project API disabled |
| 103 | `MerchantOrderID` missing |
| 104 | `OrderPrice` missing or ≤ 0 |
| 108 | Amount below the project minimum |
| 109 | Amount above the project maximum (also: capture amount larger than the authorized amount) |
| 111 | Redirect URL missing or badly encoded |
| 112 | `Mlogo` isn't a URL |
| 113 | `Items` malformed (also: capturing an order that isn't on hold) |
| 116 | Saved card not found |
| 117 | UniPAY checkout temporarily disabled |
| 119 | Unknown alias |
| 120 | `CallBackUrl` missing |
| 122 | Refund `Reason` missing |
| 123 | Order not found or not refundable |
| 124 | `RegularpaymentID` missing (card details) |
| 126, 127 | Currency rate out of date / not configured |
| 128, 129 | Payment type or saved-card channel not available |
| 130 | Card saving / saved-card charging not enabled |
| 132 | Preauthorization not enabled |
| 134 | Subscription plan not found |
| 135 | Subscription contract not found (also: `get-details` order not found) |
| 403 | Unknown merchant |
| 500 | Refund rule (message says which: `REFUND_IS_IN_PROCESSING`, `YOU_HAVE_NO_ENOUGH_BALANCE_FOR_REFUND_TRANSACTION`, `ALREADY_REFUNDED_OR_AMOUNT_IS_WRONG`, …) |
| 504 | Unknown currency |

Callback `ErrorCode` values on failed payments are bank decline codes (for example
`116` = insufficient funds on some acquirers), so they can overlap with the numbers above. Read
them only in the context of a `Status=13` callback. `GET /v3/info/error-list` (no token
needed) returns the codes and messages UniPAY knows about.
