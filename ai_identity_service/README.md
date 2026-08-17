# TUGON AI Identity Service

Python API for registration identity verification using PaddleOCR and DeepFace.

## Setup

```powershell
cd C:\xampp\htdocs\ParishSystem\ai_identity_service
python -m venv .venv
.\.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8765
```

Then configure PHP with:

```php
defineSecurityConstant('AI_IDENTITY_API_URL', 'http://127.0.0.1:8765/verify');
```

or set the environment variable:

```powershell
$env:AI_IDENTITY_API_URL='http://127.0.0.1:8765/verify'
```

The PHP system will automatically fall back to the local OCR path when this service is unavailable.
