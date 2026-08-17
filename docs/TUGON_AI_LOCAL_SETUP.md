# TUGON AI local setup and test guide

TUGON AI runs locally through Ollama. The browser calls TUGON's authenticated PHP API; it never calls Ollama directly.

## Requirements

- Windows with XAMPP, Apache, PHP 8+, MySQL, and PHP cURL enabled
- Ollama installed on the same machine as the PHP application
- The `llama3.2` model, unless `OLLAMA_MODEL` is configured to another local model

## Start the local AI service

Open PowerShell and run:

```powershell
ollama pull llama3.2
ollama serve
```

In another terminal, confirm the service and installed models:

```powershell
Invoke-RestMethod http://localhost:11434/api/tags
```

The default application configuration is:

```text
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=llama3.2
OLLAMA_TIMEOUT=120
```

Optional overrides are documented in `config/ollama.example.env`. Add overrides to the project `.env`; do not place configuration in browser JavaScript.

## Database

The existing `chatbot_knowledge` and `chatbot_inquiries` tables are reused. Apply the additive source-metadata migration if upgrading an existing database:

```powershell
C:\xampp\mysql\bin\mysql.exe -uroot parish_management_system < database\migrations\007_add_chatbot_knowledge_source.sql
```

Administrators with the `ai.use` permission can manage verified information from **Admin → AI Knowledge Base**. Only active entries are eligible for retrieval. Record a source for each official entry whenever possible.

## Manual test procedure

1. Log in as a parishioner and confirm the floating TUGON AI button appears.
2. Open the panel. Confirm the welcome message, quick questions, and `AI Online` status.
3. Send `Hello`. Confirm a response returns with `ai_engine` set to `ollama` in the API response.
4. Ask `What are the requirements for baptism?`. Confirm the reply matches active knowledge-base content.
5. Ask `Ano po ang requirements sa binyag?`, then a Taglish follow-up. Confirm the response follows the user's language.
6. Ask about an official fee or schedule that is absent from the knowledge base. Confirm TUGON AI says it lacks verified information and does not guess.
7. Ask a follow-up such as `How much does it cost?` after a sacrament question. Confirm recent bounded history is considered.
8. Stop Ollama and send another known question. Confirm the UI reports that TUGON AI is unavailable without exposing technical errors. Restart Ollama and retry without changing application code.
9. Temporarily configure a nonexistent `OLLAMA_MODEL`. Confirm the model-unavailable message is returned safely.
10. Submit an empty message, a message over 2,000 characters, invalid JSON, a missing/invalid CSRF token, and more than ten requests within one minute. Confirm validation or rate-limit errors.
11. Call the chat endpoint while logged out and with `GET`. Confirm `401` and `405` responses respectively.
12. Submit strings containing SQL syntax and `<script>` markup. Confirm no SQL executes and the UI renders the content as text.
13. Confirm a parishioner cannot retrieve another person's sacramental record, request, reservation, audit log, password, or administrator data.
14. Test the panel at desktop, tablet, and mobile widths. Confirm messages auto-scroll and Enter sends while Shift+Enter inserts a line break.

## Deployment warning

`localhost:11434` always means the machine running PHP. Moving TUGON to shared or remote hosting will not make it able to reach Ollama on a developer's personal computer. For a future parish server, set `OLLAMA_BASE_URL` to a protected internal Ollama host. Do not expose Ollama directly to the public internet; use network controls and keep PHP as the application intermediary.
