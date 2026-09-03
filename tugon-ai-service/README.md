# TUGON AI — FastAPI Microservice Backend

Dedicated Python/FastAPI microservice for **TUGON AI** (Parishioner Assistant) using Google GenAI SDK (`gemini-2.5-flash`).

---

## 🚀 Quick Start (Local Run)

1. **Install Python dependencies:**
   ```bash
   pip install -r requirements.txt
   ```

2. **Set your API Key:**
   ```bash
   # Windows PowerShell
   $env:GEMINI_API_KEY="your_api_key_here"

   # Linux/macOS
   export GEMINI_API_KEY="your_api_key_here"
   ```

3. **Start the server:**
   ```bash
   python main.py
   # or
   uvicorn main:app --reload --port 8000
   ```

4. **Verify Health:**
   ```bash
   curl http://localhost:8000/healthz
   ```

---

## 🔗 Connecting to TUGON PHP Application

In your main TUGON PHP `.env`:
```env
GEMINI_GATEWAY_URL=http://localhost:8000/api/chat
# or deployed URL:
# GEMINI_GATEWAY_URL=https://your-tugon-ai.up.railway.app/api/chat
```

---

## 📦 Deployment (Railway / Render / Docker)

- **Railway:** Automatically deploys via the included `Dockerfile` or `Procfile`. Set the `GEMINI_API_KEY` variable in the Railway project dashboard.
- **Docker:**
  ```bash
  docker build -t tugon-ai-service .
  docker run -p 8000:8000 -e GEMINI_API_KEY="your_key" tugon-ai-service
  ```
