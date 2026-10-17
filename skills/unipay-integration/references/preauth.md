# Preauthorization: authorize now, capture later

Use it when the final amount isn't known at checkout (hotels, rentals, made-to-order goods).
Preauthorization must be enabled for the project by UniPAY (otherwise error 132), and it works
with UniPAY's TBC and Bank of Georgia acquiring.

## 1. Authorize

Create the order as usual with `"OrderType": "preauth"`:

```json
{"MerchantOrderID": "BOOK-77-a1", "OrderPrice": "300.00", "OrderCurrency": "GEL", "OrderType": "preauth",
 "SuccessRedirectUrl": "…", "CancelRedirectUrl": "…", "CallBackUrl": "…", "Language": "en"}
```

When the payer completes the bank step, the order goes to **status 2 (Hold)**: the amount is
reserved on the card but not charged. **No callback is sent at this point.** After the payer
returns, check with `GET /v3/{alias}/order/get-details/{hash}` until `status` is 2.
A failed authorization still sends the usual `Status=13` callback.

Don't combine `OrderType: "preauth"` with `InApp` or `RegularPaymentID`.

## 2. Capture: `POST /v3/{alias}/order/confirm`

```http
POST https://apiv2.unipay.com/v3/custom/order/confirm
Authorization: Bearer <auth_token>
Content-Type: application/json
Accept: application/json

{"OrderHashID": "MP5005000012343A1B2C3D4E5F6", "Amount": "245.00"}
```

| Field | Required | Value |
|---|---|---|
| `OrderHashID` | yes | the held order's hash |
| `Amount` | yes | amount to charge, at most the authorized amount. Less is a partial capture. |

- Success: `{"errorcode":0,"message":"OK","data":{"OrderHashID":"MP…"}}`. Note the key is
  `OrderHashID` here, not `UnipayOrderHashID`.
- You can capture **once**. The order then settles like a normal payment and a `Status=3`
  callback with `Amount` = the captured amount follows. If the bank rejects the capture, the
  order becomes 13 and a `Status=13` callback is sent.
- Errors from this endpoint are **not** HTTP 200:

| HTTP | `errorcode` | Meaning |
|---|---|---|
| 400 | 400 | missing field, or no such order (`errors` names the field) |
| 500 | 109 | `Amount` larger than the authorized amount |
| 500 | 113 | the order isn't on hold (already captured, failed, or not a preauth) |

## Releasing a hold

There's no API call to cancel a hold, and refunds don't accept held orders. An uncaptured
authorization is released by the payer's bank when it expires, on the bank's schedule. If you
won't charge the payer, don't capture, and contact UniPAY support if the hold must be released
sooner.
