# Authentication (API v3)

## Credentials

Each UniPAY project has two credential pairs, both shown in the business portal
(https://business.unipay.com) under the project's API key settings:

| | Merchant ID | Secret key | Creates real orders |
|---|---|---|---|
| Sandbox | ends in `0` | UUID | no (see "Sandbox" below) |
| Production | ends in `1` | UUID | yes |

- The business must be verified and have a project before keys are available.
- The API can be switched on and off per project in the portal. When it is off, calls fail with
  `errorcode` 101.
- The **production secret key** also verifies callbacks (see [callbacks.md](callbacks.md)).
- Keep both keys on the server (environment variables or a secret manager). Never put them in
  frontend or mobile code: the API doesn't allow browser calls (no CORS), and the key would leak.

## Get a token: `POST /v3/auth`

```http
POST https://apiv2.unipay.com/v3/auth
Content-Type: application/json
Accept: application/json

{"merchant_id": "5005000012341", "api_key": "<secret key UUID>"}
```

| Field | Required | Value |
|---|---|---|
| `merchant_id` | yes | the merchant ID (sandbox or production) |
| `api_key` | yes | the secret key of that same pair |

Success, HTTP 200:

```json
{"auth_token": "<240-character token>", "token_type": "Bearer", "expires_in": 1800}
```

Errors:

| HTTP | Body | Meaning |
|---|---|---|
| 400 | `{"message":"DATA_IS_NOT_CORRECT"}` | wrong merchant ID or key |
| 400 | `{"error":{"code":"parameter_invalid","message":"The Merchant Id Field Is Required.","param":"merchant_id"}}` | a field is missing |

If a key you just regenerated in the portal is rejected with `DATA_IS_NOT_CORRECT` while the
merchant ID is right, contact UniPAY support to have the key synced.

## Use the token

```http
Authorization: Bearer <auth_token>
Accept: application/json
Content-Type: application/json
```

- A token lives **30 minutes** (`expires_in` = 1800 seconds). There is no refresh call. Cache
  the token and request a new one about a minute before it expires, or when a call returns
  HTTP 401.
- Old tokens stay valid until they expire, so several processes may each hold their own.
- `POST /v3/auth/logout` with the token revokes it early (returns `{}`).

## Errors on any authenticated call

| HTTP | Body | Meaning |
|---|---|---|
| 401 | `{"errorcode":401,"message":"Not Autheticated"}` (sic) | token missing, wrong or expired: get a new one |
| 403 | `{"message":"Invalid ability provided."}` | the credentials aren't enabled for this API area |
| 500 | `{"errorcode":101,"message":"…","errors":[]}` | project or project API switched off |
| 500 | `{"errorcode":117,"message":"…","errors":[]}` | UniPAY checkout temporarily disabled |
| 500 | `{"errorcode":403,"message":"…","errors":[]}` | merchant not found |

Messages are often in Georgian. Branch on `errorcode` and the HTTP status, never on the text.

## API areas ("abilities")

Every new credential pair can use the **order** area: `/v3/{alias}/order/*` and `/v3/card/*`.
Other areas must be enabled by UniPAY for your merchant, and the change applies from the next
token you request:

| Area | Endpoints |
|---|---|
| order | `/v3/{alias}/order/*`, `/v3/card/*` (enabled by default) |
| subscription | `/v3/subscription/*` |
| transfer, balance, rate | `/v3/transfer`, `/v3/exchange`, `/v3/balance`, `/v3/rates` (partner features) |

`/v3/info/status-list` and `/v3/info/error-list` need no token.

## Sandbox

Sandbox credentials authenticate normally and `order/create` runs the full request validation,
but **no order is created**: the response has random numeric `UnipayOrderID` and
`UnipayOrderHashID` values and a test `Checkout` URL, and no callback is ever sent. Sandbox is
for checking authentication and request format. To test a real payment end to end, use
production credentials with a small amount and refund it.
