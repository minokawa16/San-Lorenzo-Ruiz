# ⛪ 24/7 AI Church Assistant Backend Server

A production-ready, lightweight Node.js (Express) AI backend server powered by **Google Gemini** with dynamic church knowledge grounding, multi-turn history, and robust Tagalog/Taglish typo-tolerant NLP. Designed for zero-config deployment on **Railway**.

---

## 🚀 Key Features

- **Google Gemini Engine**: Powered by `@google/generative-ai` with automatic model fallback (`gemini-1.5-flash`, `gemini-2.0-flash`, `gemini-1.5-pro`).
- **Dynamic Context Grounding**: Loads `churchData.json` dynamically and injects it into the LLM system prompt.
- **Tagalog, Taglish & Typo NLP**: Understands shorthand and colloquial Filipino texting slang (e.g., *"misa bukas sched po ano"*, *"bptsm rqrmnts"*, *"kailan pde pakasal"*, *"hm po bayad"*).
- **Pastoral Persona**: Welcoming, peaceful, respectful tone with natural use of Filipino respect particles (*"po"* and *"opo"*).
- **Anti-Hallucination & Safe Fallback**: Adheres strictly to church records and gracefully directs unlisted inquiries to the Parish Office with contact details and operating hours.
- **Production Ready**: Security headers (`helmet`), CORS, health check endpoint, graceful `SIGTERM`/`SIGINT` shutdown, and Docker containerization.

---

## 📂 Project Structure

```
church-ai-backend/
├── churchData.json      # Dynamic church knowledge base (Mass, Sacraments, Office, Contacts)
├── Dockerfile           # Production multi-layer Docker container
├── package.json         # Dependencies and start scripts
├── server.js            # Main Express server and Gemini integration
├── test.js              # Integration test suite
├── .dockerignore        # Docker build ignore file
├── .env.example         # Sample environment variables
├── .gitignore           # Git ignore file
└── README.md            # Documentation and Railway deployment guide
```

---

## 📡 API Endpoints

### 1. Health Check
- **Route**: `GET /`
- **Response**:
```json
{
  "status": "online",
  "service": "TUGON Parish Guide AI Assistant Backend",
  "parish": "San Lorenzo Ruiz Parish",
  "version": "1.0.0",
  "timestamp": "2026-09-02T14:50:00.000Z",
  "aiEngine": "Google Gemini Configured",
  "knowledgeLoaded": true
}
```

### 2. Chatbot Conversation
- **Route**: `POST /api/chat`
- **Headers**: `Content-Type: application/json`
- **Request Body**:
```json
{
  "message": "sched po ng misa sa linggo",
  "history": [
    { "role": "user", "text": "Magandang umaga po" },
    { "role": "model", "text": "Magandang umaga po! Paano po ako makakatulong sa inyo ngayon?" }
  ]
}
```
- **Success Response (`200 OK`)**:
```json
{
  "success": true,
  "reply": "Magandang araw po! Narito po ang ating iskedyul ng Banal na Misa tuwing Linggo sa San Lorenzo Ruiz Parish:\n\n• 6:00 AM — Cebuano / Bisaya (Community Mass)\n• 7:30 AM — Tagalog / Filipino (Family Mass)\n• 9:00 AM — English (Youth & High Mass)\n• 4:00 PM — Tagalog (Afternoon Mass)\n• 5:30 PM — English (Sunday Evening Mass)\n\nMayroon din po tayong Anticipated Sunday Mass tuwing Sabado ng 5:30 PM (English). Nawa'y pagpalain po kayo at ang inyong buong pamilya!",
  "processingTimeMs": 680
}
```

---

## 🛠️ Local Development Setup

### 1. Prerequisites
- Node.js 18+ LTS
- A free Google Gemini API Key from [Google AI Studio](https://aistudio.google.com/app/apikey)

### 2. Installation
```bash
cd church-ai-backend
npm install
```

### 3. Environment Variables
Copy `.env.example` to `.env`:
```bash
cp .env.example .env
```
Edit `.env` and insert your Gemini API Key:
```env
PORT=8080
GEMINI_API_KEY=AIzaSyYourActualApiKeyHere
NODE_ENV=development
```

### 4. Run the Server
```bash
npm start
```
Or with auto-reload:
```bash
npm run dev
```

---

## 🚂 Zero-Config Deployment on Railway (Step-by-Step)

### Step 1: Push Code to GitHub
Ensure the `church-ai-backend` files are committed and pushed to your GitHub repository.

### Step 2: Create a New Railway Project
1. Log in to your [Railway Dashboard](https://railway.app/).
2. Click **"+ New Project"** -> **"Deploy from GitHub repo"**.
3. Select your repository (`San-Lorenzo-Ruiz` or your dedicated backend repository).
4. *(If in a subfolder)*: Go to **Settings** -> **Root Directory** and set it to `/church-ai-backend`.

### Step 3: Configure Environment Variables
1. Click on your newly created service in Railway.
2. Navigate to the **Variables** tab.
3. Add the following variables:
   - `GEMINI_API_KEY` = `your_actual_gemini_api_key_here`
   - `NODE_ENV` = `production`
4. *(Note: Railway automatically manages the `PORT` variable dynamically.)*

### Step 4: Generate Public Domain
1. In your service settings, navigate to the **Settings** tab.
2. Under **Networking**, click **"Generate Domain"** (e.g. `church-ai-production.up.railway.app`).
3. Test your health check in the browser or terminal:
   ```bash
   curl https://your-service-domain.up.railway.app/
   ```

### Step 5: Connect with Frontend
Set your frontend chatbot API endpoint URL to:
```javascript
const CHAT_API_URL = "https://your-service-domain.up.railway.app/api/chat";
```
