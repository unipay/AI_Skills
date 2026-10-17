# Saved cards and subscriptions

Card saving and charging must be enabled for the project by UniPAY (otherwise error 130).
Subscription endpoints also need the **subscription** API area enabled (otherwise HTTP 403).

## Save a card: `InApp: 1`

Create an order with `"InApp": 1`. The payer goes through the payment page as usual, and the
card is stored by UniPAY.

```json
{"MerchantOrderID": "CARD-42-a1", "OrderPrice": "0.50", "OrderCurrency": "GEL", "InApp": 1,
 "SuccessRedirectUrl": "…", "CancelRedirectUrl": "…", "CallBackUrl": "…", "Language": "en"}
```

- With `InApp: 1` the min/max amount checks are skipped. Whether a zero amount works
  (card verification without a charge) depends on the acquirer, so a small amount is the safe
  choice.
- The success callback (`Status=3`) carries the token:
  - `RegularPaymentID=card_…` is the saved card token. Store it against your customer.
  - `CardNumber` is base64 of the masked number, e.g. `NCAqKiogMTExMQ==` → `4 *** 1111`.
- The token works only within the project that saved it.

## Charge a saved card: `RegularPaymentID`

Create an order with the token. The payer doesn't need to be present:

```json
{"MerchantOrderID": "RENEW-42-2026-11", "OrderPrice": "19.90", "OrderCurrency": "GEL",
 "RegularPaymentID": "card_Xy12…",
 "SuccessRedirectUrl": "…", "CancelRedirectUrl": "…", "CallBackUrl": "…"}
```

- The field is **`RegularPaymentID`, with a capital P**. (The card details endpoint below uses
  `RegularpaymentID`, lowercase p.)
- `SuccessRedirectUrl` and `CancelRedirectUrl` are still required.
- The response normally has only `{"UnipayOrderHashID":"MP…"}`. Some acquirers add an
  immediate result such as `"Status": 3` or `13`. If `data.Status` is `"REDIRECT"`, the bank
  wants 3-D Secure: send the payer to `data.Checkout`.
- **The callback is the final result**, as for any order.

## Card details: `POST /v3/card/get-details`

```json
{"RegularpaymentID": "card_Xy12…"}
```

```json
{"errorcode":0,"message":"OK","data":{"recurrent_card":{
  "card_hash":"card_Xy12…","card_number":"4 *** 1111","card_expire_year":"2030",
  "card_expire_month":"01","card_brand":"Visa"}}}
```

Errors: 124 (field empty), 116 (no such card in this project). Note there's no `{alias}` in
this path.

## Subscriptions

A subscription = a **plan** (price and schedule) + a **saved card**. UniPAY charges the card on
schedule and sends a callback for each charge.

### Plans: `/v3/subscription/plan-editor`

| Call | Does |
|---|---|
| `GET /v3/subscription/plan-editor?per_page=20` | list your plans (a standard paginated JSON, no `errorcode` wrapper) |
| `POST /v3/subscription/plan-editor` | create a plan, HTTP 201 with the plan |
| `GET /v3/subscription/plan-editor/{id}` | show one plan |
| `PUT /v3/subscription/plan-editor/{id}` | replace a plan (send every field), returns `1` or `0` |
| `DELETE /v3/subscription/plan-editor/{id}` | delete, `1` or `0`; HTTP 409 if the plan has active contracts |

`{id}` is the plan's numeric `id`. Orders use the plan's **`plan_id` UUID** instead.

Plan fields:

| Field | Required | Value |
|---|---|---|
| `title` | yes | max 255 characters |
| `description` | yes | text |
| `price` | yes | amount for each recurring charge |
| `first_price` | no | amount of the first charge, if different |
| `currency` | yes | `GEL`, `USD` or `EUR` |
| `billing_interval_value` + `billing_interval_type` | yes | e.g. `1` + `monthly`. Types: `yearly`, `quarterly`, `monthly`, `weekly`, `daily`, `hourly`, `minutely` |
| `error_interval_value` + `error_interval_type` | yes | how long to wait before retrying a failed charge, e.g. `1` + `daily` |
| `maximum_billing_cycles` | yes | number of charges after which the subscription ends |
| `anchor_day` | no | day of month to bill on; send `0` for "no anchor" |
| `callback_url` | yes | **plain** (not base64) URL that receives the recurring-charge callbacks and the expiry notice |
| `minimum_billing_cycles` | no | stored only |

Validation failures return HTTP 400 `{"errorcode":400,"message":"…","errors":{…}}`.

### Start a subscription

1. Save the customer's card with an `InApp: 1` order and keep its `RegularPaymentID`.
2. Create an order with **both** the plan and the card:

```json
{"MerchantOrderID": "SUB-42-start", "SubscriptionPlanID": "<plan_id UUID>", "RegularPaymentID": "card_Xy12…",
 "SuccessRedirectUrl": "…", "CancelRedirectUrl": "…", "CallBackUrl": "…"}
```

- The amount and currency come from the plan (`first_price` if set, otherwise `price`), and
  `OrderPrice` is ignored.
- Without a valid saved card you get error 116; with an unknown plan, 134.
- When this first charge succeeds, the subscription contract is created, and the `Status=3`
  callback carries `SubscriptionContractID` and `SubscriptionContractStatus=active`. Store the
  contract ID.

### Recurring charges

- At each billing date UniPAY creates an order for `price`, charges the card, and sends the
  callback to the **plan's `callback_url`**. These callbacks have a UniPAY-generated UUID as
  `MerchantOrderID`, so match them on `SubscriptionContractID`. Verify `Hash` as usual.
- A failed charge is retried after the plan's error interval. After 3 failed charges in a row
  the contract becomes `suspended` and is no longer charged; later it becomes `inactive`, and a
  signed "subscription expired" notice goes to the plan's `callback_url`
  (see [callbacks.md](callbacks.md#subscription-expired-notice)).
- After `maximum_billing_cycles` charges the contract becomes `inactive`.

### Manage a contract

All take `{"SubscriptionContractID": "<uuid>"}` and use your token:

| Call | Does | Response |
|---|---|---|
| `POST /v3/subscription/info` | contract status, next billing date, card and plan | see below |
| `POST /v3/subscription/cancel` | sets the contract `inactive` (no refund, no callback) | `{"errorcode":0,"message":"OK"}` |
| `POST /v3/subscription/update` | with `RegularPaymentID`: switch the contract to another saved card of the same project | `{"errorcode":0,"message":"OK"}` |

```json
{"errorcode":0,"message":"OK","data":{
  "subscription_contract_id":"9b2f6c1e-…","next_billing_date":"2026-11-17T10:00:00+04:00","status":"active",
  "card":{"card_hash":"card_…","card_number":"4 *** 1111","card_expire_year":"2030","card_expire_month":"01","card_brand":"Visa"},
  "subscription_plan":{"plan_id":"<uuid>","title":"Pro","description":"…","price":"9.99","currency":"GEL",
                       "billing_interval_value":1,"billing_interval_type":"monthly"}}}
```

Error 135 means the contract doesn't exist, belongs to another project, or (for cancel and
update) is already inactive.
