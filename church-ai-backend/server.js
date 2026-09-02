import express from 'express';
import cors from 'cors';
import helmet from 'helmet';
import dotenv from 'dotenv';
import { readFileSync, existsSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, join } from 'path';
import { GoogleGenerativeAI } from '@google/generative-ai';

// Initialize environment configuration
dotenv.config();

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const PORT = parseInt(process.env.PORT || '8080', 10);
const API_KEY = process.env.GEMINI_API_KEY || process.env.AI_API_KEY || process.env.GOOGLE_API_KEY;

if (!API_KEY) {
  console.warn('⚠️ [WARNING] No GEMINI_API_KEY or AI_API_KEY found in environment variables. Live AI queries will fail until a key is configured.');
}

// ---------------------------------------------------------------------------
// 1. Dynamic Church Knowledge Base Loader
// ---------------------------------------------------------------------------
let churchKnowledge = null;
const churchDataPath = join(__dirname, 'churchData.json');

try {
  if (existsSync(churchDataPath)) {
    const rawData = readFileSync(churchDataPath, 'utf-8');
    churchKnowledge = JSON.parse(rawData);
    console.log(`✅ [SUCCESS] Loaded church knowledge base: ${churchKnowledge.parishInfo?.name || 'Parish Data'} from churchData.json`);
  } else {
    console.error(`❌ [ERROR] Knowledge base file not found at: ${churchDataPath}`);
    churchKnowledge = {};
  }
} catch (err) {
  console.error(`❌ [FATAL] Failed to parse churchData.json:`, err.message);
  churchKnowledge = {};
}

// ---------------------------------------------------------------------------
// 2. Battle-Tested System Instructions (Pastoral, Taglish, Anti-Hallucination)
// ---------------------------------------------------------------------------
function buildSystemPrompt(data) {
  const jsonContext = JSON.stringify(data, null, 2);

  return `You are "TUGON Parish Guide", the official 24/7 AI Church Assistant for ${data.parishInfo?.name || 'San Lorenzo Ruiz Parish'}, under the ${data.parishInfo?.diocese || 'Catholic Diocese'}.

================================================================================
CORE PERSONA & PASTORAL TONE
================================================================================
1. Character: Warm, peaceful, hospitable, patient, spiritually uplifting, and deeply respectful. You represent the Roman Catholic Church and the Parish Community.
2. Respect & Politeness ("Po" and "Opo"): When responding in Tagalog or Taglish, ALWAYS naturally incorporate Filipino respect particles ("po", "opo", "ninyo po", "maraming salamat po", "pagpalain po kayo").
3. Language Mirroring: Seamlessly understand and respond in English, Tagalog, Taglish (colloquial Filipino-English hybrid), or Cebuano. Always mirror the language and dialect of the parishioner.

================================================================================
NLP, TYPO, SLANG & REVERSE SYNTAX TOLERANCE
================================================================================
Parishioners often type on mobile devices using shorthand, inverted syntax, typos, abbreviations, or missing vowels. You must infer semantic intent with high accuracy:
- "misa bukas sched po ano" -> Identify request for Tomorrow's Mass Schedule.
- "bptsm rqrmnts" / "bnyag req" -> Identify Baptismal Requirements.
- "kailan pde pakasal" / "kasal docx" -> Identify Holy Matrimony guidelines and booking lead time.
- "hm po bayad" / "magkano certificate" -> Identify Certificate issuance and donation guidelines.
- "san po ofis" / "oras ng opisina" -> Identify Parish Office hours and address.
- "kumpisal sched" / "confess" -> Identify Reconciliation / Confession schedule.
- "maysakit blessing emergency" -> Identify Anointing of the Sick and provide emergency hotline immediately.

================================================================================
STRICT GROUNDING & ANTI-HALLUCINATION RULES
================================================================================
1. Primary Source of Truth: Base all factual answers (schedules, requirements, office hours, priests' names, contact numbers, rules) STRICTLY on the Church Knowledge Base provided below.
2. Anti-Hallucination: DO NOT invent dates, fees, sacrament policies, or canonical requirements that are not documented in the knowledge base.
3. Graceful Fallback: If a parishioner inquires about an unlisted service, specific cemetery plot reservation, complex legal marital impediment, or custom parish fee:
   - Gently explain that the specific detail is not in your current records.
   - Courteously refer them directly to the Parish Office staff or priests.
   - Always provide the Parish Office operating hours (${data.officeHours?.schedule?.[0]?.days || 'Tuesday to Saturday'}, ${data.officeHours?.schedule?.[0]?.morningHours || '8:00 AM - 12:00 PM'}) and contact number (${data.parishInfo?.emergencyHotline || 'Parish Office'}).

================================================================================
PARISH KNOWLEDGE BASE (OFFICIAL CHURCH DATA)
================================================================================
${jsonContext}

================================================================================
FORMATTING GUIDELINES
================================================================================
- Keep answers clear, well-structured, and easy to read on mobile screens.
- Use concise bullet points for requirements and schedules.
- Conclude with a warm pastoral closing (e.g., "Nawa'y pagpalain po kayo at ang inyong pamilya!", "May God bless you and your loved ones!").`;
}

// ---------------------------------------------------------------------------
// 3. Google Gemini AI Engine Setup
// ---------------------------------------------------------------------------
const genAI = API_KEY ? new GoogleGenerativeAI(API_KEY) : null;
const systemInstructionText = buildSystemPrompt(churchKnowledge);

// Recommended models in order of performance and speed
const MODEL_CANDIDATES = ['gemini-1.5-flash', 'gemini-2.0-flash', 'gemini-1.5-pro'];

// ---------------------------------------------------------------------------
// 4. Express Server Configuration
// ---------------------------------------------------------------------------
const app = express();

app.use(helmet({
  contentSecurityPolicy: false,
  crossOriginEmbedderPolicy: false
}));

app.use(cors({
  origin: '*',
  methods: ['GET', 'POST', 'OPTIONS'],
  allowedHeaders: ['Content-Type', 'Authorization']
}));

app.use(express.json({ limit: '1mb' }));

// ---------------------------------------------------------------------------
// 5. Routes
// ---------------------------------------------------------------------------

/**
 * Health Check Endpoint
 * GET /
 */
app.get('/', (req, res) => {
  res.status(200).json({
    status: 'online',
    service: 'TUGON Parish Guide AI Assistant Backend',
    parish: churchKnowledge?.parishInfo?.name || 'San Lorenzo Ruiz Parish',
    version: '1.0.0',
    timestamp: new Date().toISOString(),
    aiEngine: genAI ? 'Google Gemini Configured' : 'Missing API Key (Needs Configuration)',
    knowledgeLoaded: Boolean(churchKnowledge && Object.keys(churchKnowledge).length > 0)
  });
});

/**
 * Chatbot Conversation Endpoint
 * POST /api/chat
 * Body: { "message": string, "history": [ { "role": "user"|"model", "text": string } ] }
 */
app.post('/api/chat', async (req, res) => {
  const startTime = Date.now();

  try {
    const { message, history } = req.body;

    // Input Validation
    if (!message || typeof message !== 'string' || !message.trim()) {
      return res.status(400).json({
        success: false,
        error: 'The "message" field is required and must be a non-empty string.'
      });
    }

    const cleanMessage = message.trim();

    // Check AI Engine Availability
    if (!genAI) {
      return res.status(503).json({
        success: false,
        error: 'AI service is temporarily unconfigured. Please ensure GEMINI_API_KEY is set in environment variables.',
        fallbackReply: 'Magandang araw po! Pansamantala pong hindi available ang aming AI service. Maaari po kayong sumangguni sa aming Parish Office sa ' +
          (churchKnowledge?.parishInfo?.emergencyHotline || 'aming opisyal na numero') +
          ' tuwing Martes hanggang Sabado (8:00 AM - 5:00 PM). Maraming salamat po!'
      });
    }

    // Format Multi-turn Chat History for Gemini
    const formattedHistory = [];
    if (Array.isArray(history)) {
      for (const item of history) {
        if (!item || typeof item !== 'object') continue;
        const role = (item.role === 'user' || item.role === 'parishioner') ? 'user' : 'model';
        const text = item.text || item.message || item.content;
        if (text && typeof text === 'string' && text.trim()) {
          formattedHistory.push({
            role: role,
            parts: [{ text: text.trim() }]
          });
        }
      }
    }

    // Attempt generation across model candidates (with automatic fallback)
    let replyText = null;
    let lastError = null;

    for (const modelName of MODEL_CANDIDATES) {
      try {
        const model = genAI.getGenerativeModel({
          model: modelName,
          systemInstruction: systemInstructionText,
          generationConfig: {
            temperature: 0.35, // Balanced between pastoral warmth and strict factual accuracy
            topP: 0.95,
            topK: 40,
            maxOutputTokens: 1024
          }
        });

        const chat = model.startChat({
          history: formattedHistory
        });

        const result = await chat.sendMessage(cleanMessage);
        const response = await result.response;
        replyText = response.text();

        if (replyText) {
          break; // Successfully generated response
        }
      } catch (err) {
        console.warn(`[GEMINI WARN] Model ${modelName} encountered error:`, err.message);
        lastError = err;
      }
    }

    if (!replyText) {
      throw lastError || new Error('No reply generated by AI model.');
    }

    // Return Clean Response
    return res.status(200).json({
      success: true,
      reply: replyText.trim(),
      processingTimeMs: Date.now() - startTime
    });

  } catch (err) {
    console.error('❌ [API ERROR] /api/chat error:', err);

    return res.status(500).json({
      success: false,
      error: 'An error occurred while processing the inquiry.',
      details: process.env.NODE_ENV === 'development' ? err.message : undefined,
      reply: 'Paumanhin po, nagkaroon po ng pansamantalang aberya sa sistema. Maaari po kayong magtanong muli o tumawag sa Parish Office sa ' +
        (churchKnowledge?.parishInfo?.emergencyHotline || '+63 917 555 0199') +
        ' para sa agarang tulong. Pagpalain po kayo!'
    });
  }
});

// ---------------------------------------------------------------------------
// 6. Graceful Server Startup & Shutdown
// ---------------------------------------------------------------------------
const server = app.listen(PORT, '0.0.0.0', () => {
  console.log('====================================================');
  console.log(`⛪ TUGON Parish Guide AI Assistant Server running`);
  console.log(`📡 Listening on: http://0.0.0.0:${PORT}`);
  console.log(`🌐 Environment: ${process.env.NODE_ENV || 'production'}`);
  console.log('====================================================');
});

function handleShutdown(signal) {
  console.log(`\n🛑 [SHUTDOWN] Received ${signal}. Gracefully closing HTTP server...`);
  server.close(() => {
    console.log('✅ [SHUTDOWN] HTTP server closed cleanly. Process exiting.');
    process.exit(0);
  });

  // Force close if graceful shutdown stalls
  setTimeout(() => {
    console.error('⚠️ [SHUTDOWN] Forceful shutdown triggered after timeout.');
    process.exit(1);
  }, 10000);
}

process.on('SIGTERM', () => handleShutdown('SIGTERM'));
process.on('SIGINT', () => handleShutdown('SIGINT'));
