# ID OCR Auto-Correction for Registration

Scans a user's uploaded ID photo, extracts **Last Name, First Name, Middle
Name/Initial, and Address** (plus Date of Birth if present), and compares
each against what they typed in the registration form. Small differences
("Roy Marc" vs "Rey Mark") are auto-corrected to match the ID. Big
differences are flagged instead of silently overwritten, since that usually
means the wrong ID was uploaded or a field was misread — you don't want the
system quietly turning someone's real name or address into garbage.

**Middle name special case:** many PH IDs (UMID, driver's license, older
PhilID formats) only print a middle *initial*, not the full middle name. The
code detects this automatically — if the ID shows a single letter, it just
checks that the user's typed middle name starts with that letter, rather
than fuzzy-matching a whole name against one character.

**Address special case:** addresses are free text and OCR is noisier on them
(abbreviations like "St." vs "Street", wrapped lines, misread punctuation),
so address comparison uses a lower similarity threshold (45% by default, vs
65% for names) before flagging a mismatch.

## 1. Files in this package

```
id-ocr/
├── composer.json
├── src/
│   └── IDOCRProcessor.php      ← core OCR + comparison logic
├── public/
│   ├── api_process_id.php      ← AJAX endpoint the form calls
│   └── register_example.html   ← working front-end example
└── storage/tmp_ids/            ← created automatically, holds ID photos briefly
```

Copy `src/` and `public/api_process_id.php` into your existing PHP project.
`register_example.html` is a reference — merge its JS into your real
registration page.

## 2. Server requirements

**A. Configure OCR.space API key:**

Set the environment variable `OCR_SPACE_API_KEY` in your environment / Vercel project settings:
```bash
OCR_SPACE_API_KEY="your_ocr_space_api_key"
```

**B. Install PHP extensions** (cURL + Imagick or GD):
```bash
php -m | grep -i curl
php -m | grep -i imagick   # or gd — one of these is required for preprocessing
```

## 3. File permissions

```bash
mkdir -p storage/tmp_ids
chmod 750 storage/tmp_ids
```
Make sure `storage/tmp_ids` is **outside your public web root**, or blocked
via `.htaccess` / nginx config — it temporarily holds photos of people's
government IDs, so it must not be publicly downloadable.

## 4. How it works end to end

1. User fills in Last/First/Middle name and selects their ID photo.
2. User clicks **"Scan ID & Verify Info"** (before final submit).
3. JS sends the photo + typed values to `api_process_id.php`.
4. The script:
   - Preprocesses the image (grayscale, contrast boost, upscale) for better OCR accuracy.
   - Calls the OCR.space REST API over HTTPS to extract raw text.
   - Parses out Last Name / First Name / Middle Name (or Initial) / Address / Date of Birth using label patterns common on PH IDs (also handles unlabeled "SURNAME, GIVEN NAME" formats like driver's licenses).
   - Compares each typed field against the ID using a similarity score — with special-case logic for a middle-initial-only ID, and a more lenient threshold for the address field.
   - Deletes the uploaded ID image immediately after processing (privacy).
5. Response JSON tells the front-end, per field:
   - `match` — typed value already matches the ID.
   - `corrected` — typo detected, form field is auto-updated to the ID's value.
   - `mismatch` — too different to be a typo; user must manually fix it (submit is blocked until resolved).
   - `id_field_not_found` — OCR couldn't read that field; user should double check manually.
6. User reviews the (now corrected) fields and submits the form normally to your existing registration handler.

## 5. Tuning accuracy

- **Similarity threshold (names)** — in `api_process_id.php`, `new IDOCRProcessor($workDir, 65)`.
  The `65` is the minimum similarity % to auto-correct a name field rather
  than flag it as a mismatch. Lower it if legitimate typos are being flagged
  as mismatches; raise it if wrong values are being auto-corrected too
  aggressively.
- **Similarity threshold (address)** — set separately inside
  `compareAll()` in `src/IDOCRProcessor.php` (currently `45`), since address
  OCR is noisier. Adjust the `45` there if addresses are being flagged too
  often or too rarely.
- **Label variants** — if your users' IDs use wording not already in
  `IDOCRProcessor::parseFields()` / `parseAddress()` (e.g. a school ID that
  says "Pangalan" instead of "First Name", or "Home Address" instead of
  "Address"), add those label strings to the `$labelMap` array or the
  `$labels` array in `parseAddress()` in `src/IDOCRProcessor.php`.
- **Image quality** — encourage users to take the photo well-lit, flat (not
  angled), and filling the frame. OCR accuracy drops fast on blurry or
  glare-heavy photos. Consider adding a client-side blur/glare check before
  upload if this becomes a problem.

## 6. Cloud OCR API Integration

The application uses OCR.space REST API over HTTPS via `runCloudOcr()` requiring `OCR_SPACE_API_KEY`.

## 7. Testing checklist before going live

- [ ] Upload a clear ID photo where the typed name has a small typo → confirm it auto-corrects.
- [ ] Upload a clear ID photo where the typed name matches exactly → confirm status shows "match".
- [ ] Upload an ID belonging to someone else (wrong ID) → confirm it flags as "mismatch" and blocks submission.
- [ ] Upload a blurry/dark photo → confirm the system fails gracefully (asks user to retake) rather than corrupting data.
- [ ] Confirm `storage/tmp_ids` is not web-accessible and files get deleted after processing.
- [ ] Test with an ID format you know your actual users will have (PhilID, UMID, driver's license, school ID, etc.) and adjust `$labelMap` in `IDOCRProcessor.php` if fields aren't being found.

## 8. Data privacy note

You are handling images of government-issued IDs. At minimum:
- Don't log or store the raw ID photo beyond what's needed for the OCR pass (this code already deletes it right after processing).
- If you decide to retain ID images for compliance/audit reasons, encrypt them at rest and restrict access — check what's required under your local data privacy law (e.g. the Philippines' Data Privacy Act of 2012, if applicable) before doing so.
