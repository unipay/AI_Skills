"""Minimal UniPAY API v3 client: Python 3.9+ and `requests`.

Server-side only. The secret key must never reach a browser or a mobile app.

    unipay = UnipayClient.from_env()
    order = unipay.create_order(
        merchant_order_id="ORD-1001-a1", amount="25.50", title="Order #1001",
        success_url="https://shop.example/checkout/done?order=ORD-1001",
        cancel_url="https://shop.example/checkout/failed?order=ORD-1001",
        callback_url="https://shop.example/webhooks/unipay",
    )
    # store order["UnipayOrderHashID"] with your order, then redirect the payer to order["Checkout"]
"""

import base64
import hashlib
import hmac
import os
import time
from urllib.parse import quote

import requests


class UnipayError(Exception):
    def __init__(self, error_code, message, body=None):
        super().__init__(f"UniPAY error {error_code}: {message}")
        self.error_code = error_code
        self.body = body


def _b64(s: str) -> str:
    return base64.b64encode(s.encode("utf-8")).decode("ascii")


class UnipayClient:
    def __init__(self, merchant_id: str, secret_key: str,
                 base_url: str = "https://apiv2.unipay.com", alias: str = "custom"):
        if not merchant_id or not secret_key:
            raise ValueError("UniPAY merchant_id and secret_key are required")
        self.merchant_id = merchant_id
        self.secret_key = secret_key
        self.base_url = base_url.rstrip("/")
        self.alias = alias
        self._token = None
        self._token_expires_at = 0.0
        self._http = requests.Session()

    @classmethod
    def from_env(cls) -> "UnipayClient":
        return cls(
            os.environ.get("UNIPAY_MERCHANT_ID", ""),
            os.environ.get("UNIPAY_SECRET_KEY", ""),
            os.environ.get("UNIPAY_API_URL", "https://apiv2.unipay.com"),
        )

    def create_order(self, merchant_order_id: str, amount: str, title: str, success_url: str,
                     cancel_url: str, callback_url: str, language: str = "en", **extra) -> dict:
        """Returns data: Checkout (redirect the payer), UnipayOrderHashID (store it), UnipayOrderID."""
        body = {
            "MerchantOrderID": merchant_order_id,
            "OrderPrice": amount,  # major units, 2 decimals as a string: "25.50"
            "OrderCurrency": "GEL",
            "OrderName": _b64(title),  # always base64, see SKILL.md rule 4
            "SuccessRedirectUrl": _b64(success_url),
            "CancelRedirectUrl": _b64(cancel_url),
            "CallBackUrl": _b64(callback_url),
            "Language": language,
        }
        body.update(extra)
        return self._call("POST", f"/v3/{self.alias}/order/create", body)

    def get_order(self, order_hash: str) -> dict:
        """Returns data: order, status (3 = paid), status_label, amount (net), commission, dates."""
        return self._call("GET", f"/v3/{self.alias}/order/get-details/{quote(order_hash, safe='')}")

    def refund(self, order_hash: str, amount: str, reason: str, note: str = "") -> dict:
        return self._call("POST", f"/v3/{self.alias}/order/refund",
                          {"OrderHashID": order_hash, "Amount": amount, "Reason": reason, "Note": note})

    def _call(self, method, path, body=None, retried=False) -> dict:
        status, data = self._send(method, path, body, self._get_token())
        if status == 401 and not retried:
            self._token = None
            return self._call(method, path, body, True)
        # Order endpoints answer HTTP 200 for business errors too: errorcode decides.
        if data.get("errorcode") != 0:
            raise UnipayError(data.get("errorcode", status), data.get("message", "unexpected response"), data)
        return data.get("data") or {}

    def _get_token(self) -> str:
        if self._token and time.time() < self._token_expires_at:
            return self._token
        status, data = self._send("POST", "/v3/auth",
                                  {"merchant_id": self.merchant_id, "api_key": self.secret_key})
        if status != 200 or not data.get("auth_token"):
            raise UnipayError(status, data.get("message", "authentication failed"), data)
        self._token = data["auth_token"]
        self._token_expires_at = time.time() + int(data.get("expires_in", 1800)) - 60
        return self._token

    def _send(self, method, path, body=None, token=None):
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        if token:
            headers["Authorization"] = f"Bearer {token}"
        res = self._http.request(method, self.base_url + path, json=body, headers=headers, timeout=60)
        try:
            data = res.json()  # JSON even when Content-Type says text/html
        except ValueError:
            data = {"message": res.text[:200]}
        return res.status_code, data if isinstance(data, dict) else {"message": str(data)[:200]}


def verify_callback(query, production_secret_key: str) -> bool:
    """Checks a callback's Hash. Pass the query parameters (request.args), not the body: the body is "[]".
    The secret is the PRODUCTION secret key."""
    try:
        s = f"{query['UnipayOrderID']}|{query['MerchantOrderID']}|{query['Status']}|{production_secret_key}"
        received = query["Hash"]
    except KeyError:
        return False
    expected = hashlib.sha256(s.encode("utf-8")).hexdigest()
    return hmac.compare_digest(expected, str(received).lower())


# Callback endpoint with Flask. Sketch: replace find_order/mark_paid with your own storage.
#
# @app.post("/webhooks/unipay")
# def unipay_callback():
#     q = request.args                                         # all fields are in the query string
#     if not verify_callback(q, os.environ["UNIPAY_SECRET_KEY"]):
#         return "", 400
#     order = find_order_by_unipay_hash(q["UnipayOrderID"])   # the hash you stored at creation
#     if order and q["Status"] == "3" and q.get("Amount") == order.amount:
#         mark_paid_once(order)                                # idempotent: callbacks can repeat
#     elif order and q["Status"] in ("13", "22", "-1"):
#         mark_failed_if_still_pending(order)
#     return "", 200                                           # answer fast; any 2xx stops retries
