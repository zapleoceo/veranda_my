const GOOGLE = 'https://generativelanguage.googleapis.com';

function json(obj, status = 200, extraHeaders = {}) {
  const h = new Headers(Object.assign({ 'content-type': 'application/json; charset=utf-8' }, extraHeaders));
  return new Response(JSON.stringify(obj), { status, headers: h });
}

function getKeys(env) {
  return [
    env?.GEMINI_API_KEY,
    env?.GEMINI_API_KEY1,
    env?.GEMINI_API_KEY2,
    env?.GEMINI_API_KEY3,
    env?.gemini_key4,
  ].filter(k => typeof k === 'string' && k.trim() !== '');
}

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    const pfx = '/__gemini';
    if (!url.pathname.startsWith(pfx)) return new Response('not found', { status: 404 });

    const subPath = url.pathname.slice(pfx.length) || '/';
    if (subPath === '/' || subPath === '/health' || subPath === '/healthz') {
      return json({ ok: true });
    }

    const proxyVal  = (env && typeof env.PROXY_KEY === 'string') ? env.PROXY_KEY : '';
    const keyHeader = request.headers.get('X-Veranda-Key') || '';
    const keys      = getKeys(env);

    if (subPath === '/_debug') {
      return json({
        has_env:               !!env,
        has_proxy_key:         proxyVal.length > 0,
        proxy_key_len:         proxyVal.length,
        key_count:             keys.length,
        x_veranda_key_present: keyHeader.length > 0,
        x_veranda_key_matches: (keyHeader !== '' && proxyVal !== '' && keyHeader === proxyVal),
      });
    }

    if (request.method !== 'POST') {
      return new Response('method not allowed', { status: 405, headers: { allow: 'POST' } });
    }

    if (!proxyVal || keyHeader !== proxyVal) {
      return new Response('forbidden', { status: 403 });
    }

    if (keys.length === 0) {
      return json({ error: { code: 500, status: 'INTERNAL', message: 'no Gemini keys configured' } }, 500);
    }

    if (!subPath.startsWith('/v1beta/')) {
      return new Response('not found', { status: 404 });
    }

    // Read body once — reused for every retry
    const bodyBytes = await request.arrayBuffer();

    const outHeaders = new Headers();
    outHeaders.set('content-type', 'application/json');

    // Random start index so load spreads evenly across all keys
    const start = Math.floor(Math.random() * keys.length);
    let lastResp = null;

    for (let i = 0; i < keys.length; i++) {
      const key    = keys[(start + i) % keys.length];
      const target = new URL(GOOGLE + subPath);
      target.searchParams.set('key', key);

      let resp;
      try {
        resp = await fetch(target.toString(), {
          method:  'POST',
          headers: outHeaders,
          body:    bodyBytes,
        });
      } catch {
        continue; // network error, try next key
      }

      if (resp.status === 429 || resp.status === 503) {
        lastResp = resp;
        continue; // rate limited — try next key
      }

      // Success or definitive error (400, 401, etc.) — return immediately
      const h = new Headers(resp.headers);
      h.set('cache-control', 'no-store');
      return new Response(resp.body, { status: resp.status, headers: h });
    }

    // All keys exhausted
    const fallback = lastResp ? await lastResp.arrayBuffer() : new ArrayBuffer(0);
    const h = new Headers();
    h.set('content-type', 'application/json; charset=utf-8');
    h.set('cache-control', 'no-store');
    return new Response(fallback, { status: lastResp?.status ?? 429, headers: h });
  },
};
