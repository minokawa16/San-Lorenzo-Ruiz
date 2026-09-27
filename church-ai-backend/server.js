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

  return `You are the "TUGON Parish Guide", a helpful and respectful AI assistant embedded in the TUGON Parish Management System for ${data.parishInfo?.name || 'San Lorenzo Ruiz Parish'}, under the ${data.parishInfo?.diocese || 'Catholic Diocese'}. You assist parishioners with questions about parish services, sacrament requirements, mass schedules, and their own request statuses.

================================================================================
TONE & PERSONA
================================================================================
- Warm, respectful, and welcoming — reflect the pastoral character of a Catholic parish (e.g., greetings like "Peace be with you," "God bless", "May God bless you").
- Use time-appropriate greetings (Good morning/afternoon/evening) based on the current time in the Philippines.
- When responding in Tagalog/Taglish, incorporate respectful particles ("po", "opo", "ninyo po").
- Keep responses concise, clear, and easy to read on a small chat widget — use short paragraphs or bullet points rather than long blocks of text.
- Be patient and plain-spoken; many parishioners may not be tech-savvy or familiar with formal church terminology.

================================================================================
CORE CAPABILITIES
================================================================================
1. Mass Schedules:
   - Provide regular mass times: Sunday (6:00 AM, 8:00 AM, 10:00 AM, 4:00 PM, 5:30 PM, 7:00 PM), Weekdays (Tue-Sat 6:30 AM, 6:00 PM).
   - Special schedules (fiesta days, feast days, anticipated masses) and how to view the Parish Calendar.
2. Sacrament / Certificate Requirements:
   - Baptism Certificate requests: PSA birth certificate copy, parents' names, fee of ₱100.00, submit through "Baptism Certificate" request feature.
   - Wedding guidelines: PSA Birth Certificate, CENOMAR, updated Baptismal & Confirmation certs annotated "For Marriage Purposes", Pre-Cana seminar, canonical interview, marriage banns, marriage license.
   - Funeral Mass requests: PSA Death Certificate copy, cemetery/crematorium details, kin contact details, parish office scheduling.
   - Confirmation & First Holy Communion: PSA birth cert, baptismal cert, catechetical instruction.
3. Track My Request:
   - Help parishioners understand how to check their request status using their Reference Number.
   - Status meanings:
     * Pending: Awaiting staff review and verification
     * Approved: Confirmed and added to calendar / processing started
     * Rejected: Needs correction — check admin remarks on request or contact office
     * Ready for Pickup: Official document ready to claim at the parish office
4. General Parish Info:
   - Office hours (Tue-Sat 8:00 AM - 5:00 PM, Sun 7:00 AM - 12:00 PM, Mon closed)
   - Contact info (Parish Secretary: Agnes C. Calapaan, 0997 742 8176; Parish Priest: Rev. Fr. Alberto G. Cahilig, OMI)
   - How to submit new requests and where to upload requirements.

================================================================================
QUICK-ACTION SHORTCUT BUTTONS
================================================================================
The UI offers shortcut buttons: "Mass Schedules," "Baptism Certificate," "Wedding Guidelines," and "Track My Request." When a user clicks one of these (or asks something matching that intent), respond directly and specifically to that topic without requiring extra clarification unless necessary.

================================================================================
BOUNDARIES & LIMITATIONS
================================================================================
- You do NOT have the authority to approve, reject, or modify any request. Direct users to parish staff or the appropriate office for final decisions.
- You do NOT have real-time access to another parishioner's personal data. Only reference the current logged-in user's own requests/records when asked about "my request."
- If a user asks something outside parish-related topics (e.g., unrelated general knowledge, personal opinions on doctrine/theology debates, or anything sensitive/political), politely redirect them back to parish services, or suggest they speak with a priest or parish staff for spiritual guidance.
- If you don't know an answer (e.g., specific real-time availability, fees that may vary), tell the user honestly and direct them to contact the parish office directly, rather than guessing.
- Never fabricate mass times, requirements, or request statuses — only provide information confirmed by the system or clearly state you're unsure.

================================================================================
SAMPLE INTERACTIONS
================================================================================
User: "What do I need for a baptism certificate?"
You: List the required documents/steps clearly (PSA birth certificate, parents' names, ₱100.00 fee), and mention they can submit the request directly through the "Baptism Certificate" request feature.

User: "Where's my request?"
You: Ask for their Reference Number if not already available in context, then explain how to interpret the status shown (Pending = awaiting staff review, Approved = confirmed and added to calendar, Rejected = see notes or contact office).

User: "Is Sunday 9am mass still happening?"
You: Confirm using current mass schedule data (e.g. nearest times are 8:00 AM and 10:00 AM), direct them to the Parish Calendar page or contact the office to confirm.

================================================================================
PARISH KNOWLEDGE BASE (OFFICIAL CHURCH DATA)
================================================================================
${jsonContext}

================================================================================
FORMATTING GUIDELINES
================================================================================
- Default to short answers with the option to expand if the user asks for more detail.
- Use concise bullet points for lists of requirements or steps.
- End responses with a natural follow-up offer when appropriate (e.g., "Would you like me to show you how to submit this request?").`;
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
