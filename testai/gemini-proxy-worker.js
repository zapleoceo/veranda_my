/**
 * Cloudflare Worker — Gemini API proxy with key rotation
 *
 * Environment variables (set in Cloudflare dashboard → Workers → Settings → Variables):
 *   PROXY_KEY       — secret key that PHP sends in X-Veranda-Key header
 *   GEMINI_API_KEY  — Gemini key #1
 *   GEMINI_API_KEY1 — Gemini key #2
 *   GEMINI_API_KEY2 — Gemini key #3
 *   GEMINI_API_KEY3 — Gemini key #4
 *   gemini_key4     — Gemini key #5 (optional)
 *
 * How rotation works:
 *   Each request starts from a random key (to spread load evenly).
 *   If a key returns 429 (rate limit) or 503 — tries the next one.
 *   If all keys are exhausted — returns the last 429 response.
 */

export default {
  async fetch(request, env) {
    // ── Auth ────────────────────────────────────────────────────────────────
    const proxyKey =
      request.headers.get('X-Veranda-Key') ||
      request.headers.get('X-Gemini-Proxy-Key') ||
      request.headers.get('X-Proxy-Key');

    if (!env.PROXY_KEY || proxyKey !== env.PROXY_KEY) {
      return json({ error: 'Forbidden' }, 403);
    }

    // ── Build key list (skip empty / unset) ─────────────────────────────────
    const keys = [
      env.GEMINI_API_KEY,
      env.GEMINI_API_KEY1,
      env.GEMINI_API_KEY2,
      env.GEMINI_API_KEY3,
      env.gemini_key4,
    ].filter(k => typeof k === 'string' && k.trim() !== '');

    if (keys.length === 0) {
      return json({ error: 'No Gemini keys configured' }, 500);
    }

    // ── Forward path: /v1beta/models/....:generateContent ──────────────────
    const url  = new URL(request.url);
    const path = url.pathname; // keep as-is, drop query string (key goes there)
    const body = await request.text();

    // ── Rotate: random start index so load is spread across keys ───────────
    const start = Math.floor(Math.random() * keys.length);
    let lastResponse = null;

    for (let i = 0; i < keys.length; i++) {
      const key      = keys[(start + i) % keys.length];
      const geminiUrl = `https://generativelanguage.googleapis.com${path}?key=${encodeURIComponent(key)}`;

      let resp;
      try {
        resp = await fetch(geminiUrl, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
        });
      } catch {
        // network error — try next key
        continue;
      }

      if (resp.status === 429 || resp.status === 503) {
        lastResponse = resp;
        continue; // rate-limited or overloaded → try next key
      }

      // Success (or a definitive error like 400/401 — return immediately)
      const respBody = await resp.text();
      return new Response(respBody, {
        status:  resp.status,
        headers: { 'Content-Type': 'application/json; charset=utf-8' },
      });
    }

    // All keys returned 429/503
    const fallbackBody = lastResponse ? await lastResponse.text() : '{"error":"all keys rate limited"}';
    return new Response(fallbackBody, {
      status:  lastResponse?.status ?? 429,
      headers: { 'Content-Type': 'application/json; charset=utf-8' },
    });
  },
};

function json(obj, status = 200) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json; charset=utf-8' },
  });
}
