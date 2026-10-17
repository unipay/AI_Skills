# Refunds: `POST /v3/{alias}/order/refund`

```http
POST https://apiv2.unipay.com/v3/custom/order/refund
Authorization: Bearer <auth_token>
Content-Type: application/json
Accept: application/json

{"OrderHashID": "MP5005000012343A1B2C3D4E5F6", "Amount": "10.00", "Reason": "Customer request", "Note": "Ticket #4471"}
```

| Field | Required | Value |
|---|---|---|
| `OrderHashID` | yes | the order's `UnipayOrderHashID` |
| `Amount` | yes | amount to refund, 2 decimals. **Always send it, even for a full refund.** It must also be within the project's min/max limits. |
| `Reason` | yes | non-empty text (error 122 if empty) |
| `Note` | no | free text |

Success: `{"errorcode":0,"message":"OK","data":{"UnipayOrderHashID":"MP…"}}`. That means the
refund was **accepted**. It settles afterwards, and a callback for the original order follows.

## What can be refunded

- Orders with status 3 (paid), 19 (partially refunded) or 21 (partially reversed). Orders on
  hold (2, an uncaptured preauth) and orders still settling (1) can't be refunded: error 123.
- Partial refunds are allowed when enabled for the project. The total refunded can't exceed the
  order amount (`ALREADY_REFUNDED_OR_AMOUNT_IS_WRONG`).
- **One refund at a time per project.** Wait for the previous refund's callback (or a few
  minutes) before sending the next; meanwhile you get `REFUND_IS_IN_PROCESSING`.
- **Refunds are paid from the merchant's UniPAY balance** in the order's currency. If the balance
  doesn't cover the amount plus the refund fee you get
  `YOU_HAVE_NO_ENOUGH_BALANCE_FOR_REFUND_TRANSACTION` (or `BALANCE_IS_NULL`).
- Refund-rule failures come back as `errorcode` 500 with the rule in `message`.

## Refund or reversal

UniPAY decides automatically. A recent payment (for some banks, one made the same day) is
**reversed** (cancelled before settlement) instead of refunded:

| What happened | Callback `Status` | Order status afterwards |
|---|---|---|
| Refund | `5`, even for a partial refund | 5 refunded, or 19 partially refunded |
| Reversal | `4`, even for a partial reversal | 4 reversed, or 21 partially reversed |

The callback's `Amount` for a refund includes the refund fee. Keep your own record of the
amounts you requested and use `get-details` for the resulting order status.
