# Offline TUGON AI Chatbot Setup

This chatbot runs fully offline after Ollama and the local model are installed.
It does not use Gemini, OpenAI, Claude, Hugging Face APIs, Pinecone, Firebase AI, or paid cloud AI services.

## Requirements

- XAMPP with PHP 8+
- MySQL running in XAMPP
- Ollama installed locally
- Llama 3.2 model installed locally

## Install Ollama And Llama 3.2

1. Install Ollama from the official Ollama installer.
2. Open a terminal and run:

```bash
ollama pull llama3.2
ollama serve
```

Ollama must be available at:

```text
http://localhost:11434/api/generate
```

## Configuration

The offline AI config is stored in:

```text
config/ollama.php
```

Defaults:

```php
OLLAMA_API_URL = http://localhost:11434/api/generate
OLLAMA_MODEL = llama3.2
```

## Knowledge Base

Put official chatbot information in:

```text
Admin Panel > AI Knowledge Base
```

File:

```text
admin/chatbot-knowledge.php
```

Each item supports:

- Topic
- Keywords and alternate phrases
- Official answer
- Numbered requirements or steps
- Category
- Active/inactive status

The chatbot searches this MySQL table first. If no relevant active item is found, it replies:

```text
I'm sorry. I don't have enough parish information regarding that topic. Please contact the parish office.
```

## RAG Flow

1. User asks a question.
2. PHP searches `chatbot_knowledge` in MySQL.
3. If relevant information exists, PHP builds a strict context prompt.
4. PHP sends the prompt to Ollama at `localhost:11434`.
5. Llama 3.2 formats the answer using only the retrieved context.
6. If Ollama is offline, PHP still returns the retrieved official context as a safe fallback.

## Testing

Use the real floating chatbot while logged in, or open the legacy test page:

```text
api/api/HTML
```

Ask:

```text
What are the baptism requirements?
Ano kailangan sa binyag?
What are the office hours?
```

The JSON response includes:

```json
"ai_engine": "ollama"
```

If Ollama is not running, it returns:

```json
"ai_engine": "offline-rag"
```

## Security Notes

- No cloud API key is required.
- All official parish facts come from MySQL.
- User input is validated and rendered safely on the frontend.
- Database access uses prepared statements where user input is used.
- The Ollama prompt instructs the model not to invent information.
