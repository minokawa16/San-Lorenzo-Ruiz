import 'dotenv/config';

import { GoogleGenAI } from '@google/genai';
import cors from 'cors';
import express from 'express';
import { rateLimit } from 'express-rate-limit';

const app = express();
const port = Number(process.env.PORT) || 3001;
const clientOrigin = process.env.CLIENT_ORIGIN || 'http://localhost:5173';
const model = process.env.GEMINI_MODEL || 'gemini-2.5-flash';

app.disable('x-powered-by');
app.use(express.json({ limit: '32kb' }));
app.use(
  cors({
    origin(origin, callback) {
      // Requests without an Origin header include curl and same-origin server calls.
      if (!origin || origin === clientOrigin) {
        callback(null, true);
        return;
      }

      const error = new Error('Origin is not allowed by CORS.');
      error.status = 403;
      callback(error);
    },
    methods: ['POST', 'OPTIONS'],
    allowedHeaders: ['Content-Type'],
  }),
);

const chatLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: 15,
  standardHeaders: 'draft-8',
  legacyHeaders: false,
  message: { error: 'Too many chat requests. Please wait a few minutes and try again.' },
});

app.get('/healthz', (_req, res) => {
  res.json({ status: 'ok', configured: Boolean(process.env.GEMINI_API_KEY) });
});

function messageText(item) {
  if (typeof item?.content === 'string') return item.content;
  if (typeof item?.text === 'string') return item.text;
  if (typeof item?.message === 'string') return item.message;
  return '';
}

function normalizeHistory(history) {
  if (history === undefined) return [];
  if (!Array.isArray(history)) {
    const error = new Error('history must be an array.');
    error.status = 400;
    throw error;
  }

  const normalized = [];
  for (const item of history.slice(-20)) {
    const role = item?.role === 'assistant' || item?.role === 'model' ? 'model' : item?.role;
    const text = messageText(item).trim();
    if (!['user', 'model'].includes(role) || !text) continue;
    if (normalized.length === 0 && role !== 'user') continue;

    const previous = normalized.at(-1);
    if (previous?.role === role) {
      previous.parts[0].text += `\n${text}`;
    } else {
      normalized.push({ role, parts: [{ text: text.slice(0, 4000) }] });
    }
  }

  // A new user message follows this history, so the preceding complete turn
  // must end with a model response.
  if (normalized.at(-1)?.role === 'user') normalized.pop();
  return normalized;
}

app.post('/api/chat', chatLimiter, async (req, res, next) => {
  try {
    const message = typeof req.body?.message === 'string' ? req.body.message.trim() : '';
    if (!message) {
      return res.status(400).json({ error: 'message is required and must be a non-empty string.' });
    }
    if (message.length > 16000) {
      return res.status(413).json({ error: 'message must be 16,000 characters or fewer.' });
    }

    // SECURITY BOUNDARY: the API key is read only by this server process.
    // It is never included in a response and is never available to the React app.
    const apiKey = process.env.GEMINI_API_KEY;
    if (!apiKey) {
      return res.status(503).json({ error: 'Gemini API is not configured on the server.' });
    }

    const ai = new GoogleGenAI({ apiKey });
    const chat = ai.chats.create({
      model,
      history: normalizeHistory(req.body?.history),
    });
    const response = await chat.sendMessage({ message });
    const reply = response.text?.trim();

    if (!reply) {
      return res.status(502).json({ error: 'Gemini returned an empty response.' });
    }
    return res.json({ reply });
  } catch (error) {
    return next(error);
  }
});

app.use((error, _req, res, _next) => {
  const upstreamStatus = Number(error?.status || error?.statusCode || error?.code);
  if (upstreamStatus === 429 || /rate|quota|resource_exhausted/i.test(error?.message || '')) {
    return res.status(429).json({ error: 'Gemini rate limit reached. Please wait and try again.' });
  }
  if (error?.status === 400 || error?.status === 403) {
    return res.status(error.status).json({ error: error.message });
  }
  if (/fetch|network|socket|timeout|connect/i.test(error?.message || '')) {
    return res.status(502).json({ error: 'Unable to reach Gemini. Please try again shortly.' });
  }

  console.error('Chat request failed:', error?.message || error);
  return res.status(502).json({ error: 'Gemini could not complete the request.' });
});

app.listen(port, () => {
  console.log(`Gemini chat server listening on http://localhost:${port}`);
});
