import os
import re
import tempfile
from datetime import datetime
from typing import Dict, List, Optional

import cv2
import numpy as np
from deepface import DeepFace
from fastapi import FastAPI, File, UploadFile
from PIL import Image, ImageEnhance, ImageFilter
from paddleocr import PaddleOCR


app = FastAPI(title="TUGON AI Identity Verification Service")
ocr_engine = PaddleOCR(use_angle_cls=True, lang="en", show_log=False)


def save_upload(upload: UploadFile, prefix: str) -> str:
    suffix = os.path.splitext(upload.filename or "")[1].lower() or ".jpg"
    handle, path = tempfile.mkstemp(prefix=prefix, suffix=suffix)
    with os.fdopen(handle, "wb") as target:
        target.write(upload.file.read())
    return path


def preprocess_image(path: str) -> str:
    image = Image.open(path).convert("RGB")
    image.thumbnail((2600, 2600))
    image = ImageEnhance.Contrast(image).enhance(1.55)
    image = ImageEnhance.Sharpness(image).enhance(1.75)
    image = image.filter(ImageFilter.MedianFilter(size=3))
    cv_image = cv2.cvtColor(np.array(image), cv2.COLOR_RGB2BGR)
    gray = cv2.cvtColor(cv_image, cv2.COLOR_BGR2GRAY)
    gray = cv2.fastNlMeansDenoising(gray, None, 12, 7, 21)
    binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]
    coords = np.column_stack(np.where(binary < 255))
    if coords.size:
        angle = cv2.minAreaRect(coords)[-1]
        angle = -(90 + angle) if angle < -45 else -angle
        if abs(angle) <= 12:
            height, width = gray.shape[:2]
            matrix = cv2.getRotationMatrix2D((width // 2, height // 2), angle, 1.0)
            gray = cv2.warpAffine(gray, matrix, (width, height), flags=cv2.INTER_CUBIC, borderMode=cv2.BORDER_REPLICATE)
    image = Image.fromarray(gray).convert("RGB")
    handle, output = tempfile.mkstemp(prefix="tugon_ocr_", suffix=".jpg")
    os.close(handle)
    image.save(output, "JPEG", quality=94)
    return output


def ocr_text(path: str) -> Dict:
    processed = preprocess_image(path)
    try:
        result = ocr_engine.ocr(processed, cls=True)
        lines: List[str] = []
        confidences: List[float] = []
        for page in result or []:
            for item in page or []:
                if len(item) >= 2 and item[1]:
                    text, score = item[1][0], float(item[1][1])
                    if text:
                        lines.append(str(text).strip())
                        confidences.append(score)
        return {
            "text": "\n".join(lines),
            "confidence": round((sum(confidences) / len(confidences)) * 100, 2) if confidences else 0,
        }
    finally:
        safe_remove(processed)


def safe_remove(path: Optional[str]) -> None:
    if path and os.path.exists(path):
        try:
            os.remove(path)
        except OSError:
            pass


def clean(value: str) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip(" :-|")


def clean_name(value: str) -> str:
    value = re.sub(r"[^A-Za-zÑñ\s.,-]", " ", str(value or ""))
    value = re.sub(
        r"\b(REPUBLIC|PILIPINAS|PHILIPPINES|GOVERNMENT|VALID|UNTIL|SIGNATURE|ADDRESS|BIRTH|DATE|SEX|NATIONALITY|CARD|NUMBER|ID)\b",
        " ",
        value,
        flags=re.I,
    )
    return clean(value).upper()


def match_label(text: str, labels: List[str], pattern: str = r"(.{2,120})") -> str:
    label_group = "|".join(re.escape(label) for label in labels)
    regex = re.compile(rf"(?:{label_group})\s*[:\-]?\s*{pattern}", re.I)
    found = regex.search(text)
    return clean(found.group(1)) if found else ""


def normalize_date(value: str) -> str:
    value = clean(value)
    candidates = [
        "%Y-%m-%d",
        "%m/%d/%Y",
        "%d/%m/%Y",
        "%m-%d-%Y",
        "%d-%m-%Y",
        "%B %d, %Y",
        "%b %d, %Y",
        "%d %B %Y",
        "%d %b %Y",
    ]
    for fmt in candidates:
        try:
            return datetime.strptime(value, fmt).strftime("%Y-%m-%d")
        except ValueError:
            pass
    found = re.search(r"\b(\d{4}[/-]\d{1,2}[/-]\d{1,2}|\d{1,2}[/-]\d{1,2}[/-]\d{2,4}|[A-Za-z]{3,9}\s+\d{1,2},?\s+\d{4})\b", value)
    if found:
        return normalize_date(found.group(1))
    return ""


def parse_fields(text: str) -> Dict[str, str]:
    normalized = re.sub(r"[ \t]+", " ", text)
    lines = [clean(line) for line in normalized.splitlines() if clean(line)]
    compact = "\n".join(lines)

    fullname = match_label(compact, ["full name", "name", "pangalan"], r"([A-ZÑ .,-]{4,80})")
    surname = match_label(compact, ["surname", "last name", "apelyido"], r"([A-ZÑ .,-]{2,50})")
    given = match_label(compact, ["first name", "given name", "given names"], r"([A-ZÑ .,-]{2,60})")
    if not fullname and (given or surname):
        fullname = clean(f"{given} {surname}")
    fullname = clean_name(fullname)
    surname = clean_name(surname)
    given = clean_name(given)
    middle = clean_name(match_label(compact, ["middle name", "middle initial", "gitnang apelyido"], r"([A-ZÑÃ‘ .,-]{1,50})"))
    if not given and not surname and fullname:
        parts = fullname.split()
        if len(parts) > 1:
            surname = parts[-1]
            given = " ".join(parts[:-1])
    middle_initial = re.sub(r"[^A-Za-zÑñ]", "", middle[:1]).upper()

    birthdate = normalize_date(match_label(compact, ["date of birth", "birthdate", "birth date", "dob"], r"([A-Za-z0-9 ,./-]{6,32})"))
    birth_place = match_label(compact, ["place of birth", "birth place", "pook ng kapanganakan", "lugar ng kapanganakan"], r"(.{4,120})")
    address = match_label(compact, ["address", "residence", "tirahan"], r"(.{6,160})")
    if address:
        for line in lines:
            if line != address and re.search(r"\b(barangay|brgy\.?|purok|street|st\.|road|rd\.|aleosan|cotabato|city|province)\b", line, re.I):
                if line.lower() not in address.lower() and len(address) < 180:
                    address = clean(f"{address} {line}")
    id_number = match_label(compact, ["id number", "id no", "card no", "crn", "license no", "philsys no", "pcn"], r"([A-Z0-9 -]{5,40})")
    sex = match_label(compact, ["sex", "gender"], r"([A-Za-z]{1,12})")
    nationality = match_label(compact, ["nationality", "citizenship"], r"([A-Za-z ]{3,32})")

    if not birthdate:
        birthdate = normalize_date(compact)
    if not id_number:
        found = re.search(r"\b([A-Z0-9]{2,5}[- ]?[A-Z0-9]{3,6}[- ]?[A-Z0-9]{3,8}(?:[- ]?[A-Z0-9]{2,8})?)\b", compact)
        id_number = clean(found.group(1)) if found else ""
    if not sex:
        found = re.search(r"\b(MALE|FEMALE|M|F)\b", compact, re.I)
        sex = clean(found.group(1)).upper() if found else ""
    if not nationality and re.search(r"\bFILIPINO\b", compact, re.I):
        nationality = "Filipino"

    return {
        "fullname": fullname,
        "full_name": fullname,
        "surname": surname,
        "first_name": given,
        "middle_initial": middle_initial,
        "birthdate": birthdate,
        "birth_place": birth_place,
        "address": address,
        "id_number": id_number,
        "sex": sex.title() if len(sex) > 1 else sex.upper(),
        "nationality": nationality.title(),
    }


def image_quality(path: str) -> Dict:
    image = cv2.imread(path)
    if image is None:
        return {"ok": False, "message": "Image could not be opened.", "score": 0}
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur = float(cv2.Laplacian(gray, cv2.CV_64F).var())
    brightness = float(np.mean(gray))
    score = min(100, int((blur / 4) + (100 - abs(130 - brightness)) / 2))
    ok = blur >= 35 and 45 <= brightness <= 225
    return {
        "ok": ok,
        "message": "Image quality is acceptable." if ok else "Image is blurry or poorly lit.",
        "score": max(0, score),
    }


def verify_faces(front_path: str, selfie_path: str) -> Dict:
    try:
        result = DeepFace.verify(
            img1_path=selfie_path,
            img2_path=front_path,
            model_name="Facenet512",
            detector_backend="opencv",
            enforce_detection=True,
        )
        return {
            "verified": bool(result.get("verified")),
            "distance": float(result.get("distance", 1)),
            "threshold": float(result.get("threshold", 0)),
        }
    except Exception as exc:
        return {
            "verified": False,
            "distance": 1,
            "threshold": 0,
            "error": str(exc),
        }


@app.get("/health")
def health():
    return {"ok": True, "service": "tugon-ai-identity"}


@app.post("/verify")
async def verify_identity(
    front_id: UploadFile = File(...),
    back_id: UploadFile = File(...),
    selfie: UploadFile = File(...),
):
    paths = []
    try:
        front_path = save_upload(front_id, "tugon_front_")
        back_path = save_upload(back_id, "tugon_back_")
        selfie_path = save_upload(selfie, "tugon_selfie_")
        paths.extend([front_path, back_path, selfie_path])

        front_quality = image_quality(front_path)
        back_quality = image_quality(back_path)
        front_ocr = ocr_text(front_path)
        back_ocr = ocr_text(back_path)
        combined_text = "[FRONT ID]\n" + front_ocr["text"] + "\n\n[BACK ID]\n" + back_ocr["text"]
        fields = parse_fields(combined_text)
        face = verify_faces(front_path, selfie_path)
        confidence = round(max(front_ocr["confidence"], back_ocr["confidence"]), 2)

        return {
            **fields,
            "face_verified": face["verified"],
            "verified": face["verified"],
            "distance": face.get("distance"),
            "confidence_score": confidence,
            "ocr_text": combined_text,
            "quality": {
                "front": front_quality,
                "back": back_quality,
            },
            "errors": [face.get("error")] if face.get("error") else [],
        }
    finally:
        for path in paths:
            safe_remove(path)
