import http from 'node:http';
import { timingSafeEqual } from 'node:crypto';

const PORT = Number(process.env.PORT || 8080);
const PROXY_SECRET = (process.env.PROXY_SECRET || '').trim();
const MAX_BYTES = 32 * 1024 * 1024;
const ALLOWED_PATH = /^\/v1beta\/models\/[A-Za-z0-9._-]+:generateContent$/;

function verifySecret(provided) {
  if (!PROXY_SECRET) return true;
  if (typeof provided !== 'string' || !provided) return false;
  const a = Buffer.from(provided, 'utf8');
  const b = Buffer.from(PROXY_SECRET, 'utf8');
  return a.length === b.length && timingSafeEqual(a, b);
}

http.createServer(async (req, res) => {
  if (req.method === 'GET' && (req.url === '/' || req.url === '/health')) {
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('ok');
    return;
  }

  const pathOnly = (req.url || '').split('?', 1)[0];
  if (req.method !== 'POST' || !ALLOWED_PATH.test(pathOnly)) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: { message: 'Unsupported route.' } }));
    return;
  }

  if (!verifySecret(req.headers['x-agap-proxy-secret'])) {
    res.writeHead(403, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: { message: 'Forbidden.' } }));
    return;
  }

  try {
    const chunks = [];
    let total = 0;
    for await (const chunk of req) {
      total += chunk.length;
      if (total > MAX_BYTES) {
        res.writeHead(413, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: { message: 'Payload too large.' } }));
        return;
      }
      chunks.push(chunk);
    }

    const upstream = await fetch(`https://generativelanguage.googleapis.com${pathOnly}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'x-goog-api-key': String(req.headers['x-goog-api-key'] || ''),
      },
      body: Buffer.concat(chunks),
    });

    const text = await upstream.text();
    res.writeHead(upstream.status, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(text);
  } catch (err) {
    res.writeHead(502, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: { message: 'Upstream Gemini relay error.' } }));
  }
}).listen(PORT, () => {
  console.log(`AGAP Gemini relay listening on :${PORT}`);
});
