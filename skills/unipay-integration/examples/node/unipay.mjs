// Minimal UniPAY API v3 client: Node 18+ (built-in fetch and crypto), no dependencies.
// Server-side only. The secret key must never reach a browser or a mobile app.
//
//   import { UnipayClient, verifyCallback } from './unipay.mjs';
//   const unipay = UnipayClient.fromEnv();
//   const order = await unipay.createOrder({
//     merchantOrderId: 'ORD-1001-a1', amount: '25.50', title: 'Order #1001',
//     successUrl: 'https://shop.example/checkout/done?order=ORD-1001',
//     cancelUrl: 'https://shop.example/checkout/failed?order=ORD-1001',
//     callbackUrl: 'https://shop.example/webhooks/unipay',
//   });
//   // store order.UnipayOrderHashID with your order, then redirect the payer to order.Checkout

import crypto from 'node:crypto';

export class UnipayError extends Error {
  constructor(errorCode, message, body = null) {
    super(`UniPAY error ${errorCode}: ${message}`);
    this.errorCode = errorCode;
    this.body = body;
  }
}

const b64 = (s) => Buffer.from(s, 'utf8').toString('base64');

export class UnipayClient {
  #token = null;
  #tokenExpiresAt = 0;

  constructor({ merchantId, secretKey, baseUrl = 'https://apiv2.unipay.com', alias = 'custom' }) {
    if (!merchantId || !secretKey) throw new Error('UniPAY merchantId and secretKey are required');
    Object.assign(this, { merchantId, secretKey, baseUrl, alias });
  }

  static fromEnv() {
    return new UnipayClient({
      merchantId: process.env.UNIPAY_MERCHANT_ID,
      secretKey: process.env.UNIPAY_SECRET_KEY,
      baseUrl: process.env.UNIPAY_API_URL || 'https://apiv2.unipay.com',
    });
  }

  /** Returns data: { Checkout, UnipayOrderHashID, UnipayOrderID }. */
  createOrder({ merchantOrderId, amount, title, successUrl, cancelUrl, callbackUrl, language = 'en', extra = {} }) {
    return this.#call('POST', `/v3/${this.alias}/order/create`, {
      MerchantOrderID: merchantOrderId,
      OrderPrice: amount, // major units, 2 decimals as a string: "25.50"
      OrderCurrency: 'GEL',
      OrderName: b64(title), // always base64, see SKILL.md rule 4
      SuccessRedirectUrl: b64(successUrl),
      CancelRedirectUrl: b64(cancelUrl),
      CallBackUrl: b64(callbackUrl),
      Language: language,
      ...extra,
    });
  }

  /** Returns data: { order, status (3 = paid), status_label, amount (net), commission, completed_at, created_at }. */
  getOrder(orderHash) {
    return this.#call('GET', `/v3/${this.alias}/order/get-details/${encodeURIComponent(orderHash)}`);
  }

  refund(orderHash, amount, reason, note = '') {
    return this.#call('POST', `/v3/${this.alias}/order/refund`, {
      OrderHashID: orderHash, Amount: amount, Reason: reason, Note: note,
    });
  }

  async #call(method, path, body, retried = false) {
    const { status, json } = await this.#send(method, path, body, await this.#getToken());
    if (status === 401 && !retried) {
      this.#token = null;
      return this.#call(method, path, body, true);
    }
    // Order endpoints answer HTTP 200 for business errors too: errorcode decides.
    if (json.errorcode !== 0) {
      throw new UnipayError(json.errorcode ?? status, json.message ?? 'unexpected response', json);
    }
    return json.data ?? {};
  }

  async #getToken() {
    if (this.#token && Date.now() < this.#tokenExpiresAt) return this.#token;
    const { status, json } = await this.#send('POST', '/v3/auth', {
      merchant_id: this.merchantId,
      api_key: this.secretKey,
    });
    if (status !== 200 || !json.auth_token) {
      throw new UnipayError(status, json.message ?? 'authentication failed', json);
    }
    this.#token = json.auth_token;
    this.#tokenExpiresAt = Date.now() + ((json.expires_in ?? 1800) - 60) * 1000;
    return this.#token;
  }

  async #send(method, path, body, token = null) {
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    const res = await fetch(this.baseUrl + path, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      signal: AbortSignal.timeout(60_000),
    });
    const text = await res.text(); // JSON even when Content-Type says text/html
    let json;
    try {
      json = JSON.parse(text);
    } catch {
      json = { message: text.slice(0, 200) };
    }
    return { status: res.status, json };
  }
}

/**
 * Checks a callback's Hash. Pass the query parameters (req.query), not the body: the body is "[]".
 * The secret is the PRODUCTION secret key.
 */
export function verifyCallback(query, productionSecretKey) {
  const { UnipayOrderID, MerchantOrderID, Status, Hash } = query;
  if ([UnipayOrderID, MerchantOrderID, Status, Hash].some((v) => typeof v !== 'string')) return false;
  const expected = crypto
    .createHash('sha256')
    .update(`${UnipayOrderID}|${MerchantOrderID}|${Status}|${productionSecretKey}`, 'utf8')
    .digest('hex');
  const a = Buffer.from(expected);
  const b = Buffer.from(Hash.toLowerCase());
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

// Callback endpoint with Express. Sketch: replace findOrder/markPaid with your own storage.
//
// app.post('/webhooks/unipay', async (req, res) => {
//   const q = req.query;                                      // all fields are in the query string
//   if (!verifyCallback(q, process.env.UNIPAY_SECRET_KEY)) return res.sendStatus(400);
//   const order = await findOrderByUnipayHash(q.UnipayOrderID); // the hash you stored at creation
//   if (order && q.Status === '3' && q.Amount === order.amount) await markPaidOnce(order); // idempotent
//   else if (order && ['13', '22', '-1'].includes(q.Status)) await markFailedIfStillPending(order);
//   res.sendStatus(200);                                      // answer fast; any 2xx stops retries
// });
