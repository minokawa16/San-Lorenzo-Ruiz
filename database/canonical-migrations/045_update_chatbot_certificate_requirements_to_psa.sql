-- Canonical Migration 045: Update chatbot certificate requirements from Valid ID to PSA of the person on the record
-- Idempotent, safe to run multiple times, with no dropped columns or data loss.

-- 1. Update KB-31: Certificate requirements
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'Requirements for certificates: PSA of the person on the record; Record details: full name of the person on the record, approximate date or year of sacrament, parents\' full names (including mother\'s maiden name), and purpose of request (First Communion, Confirmation, Marriage Preparation, School, Passport/Travel, Employment, Legal/Personal, or Burial/Benefits).',
  `steps` = 'General Requirements for Parish Certificates:\n• PSA of the person on the record\n• Person\'s full name, approximate year of sacrament, and parents\' full names\n• Specific purpose of request',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 308 OR `topic` = 'KB-31 Certificate requirements';

-- 2. Update KB-33: How to request a certificate online
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'To request a certificate online: 1. Log in to the TUGON portal; 2. Open Request Certificate; 3. Choose Certificate Type and Purpose; 4. Enter the person\'s complete details and approximate year of sacrament; 5. Upload supporting document (PSA of the person on the record); 6. Choose payment method (GCash or cash on pick-up; for GCash, enter reference number and upload receipt); 7. Submit and save your reference number (REQ-2026-XXXX); 8. Track progress in My Requests; 9. When Completed, download signed PDF or pick it up at the office.',
  `steps` = '1. Log in to TUGON (users/request-certificate.php)\n2. Choose Certificate Type and Purpose\n3. Provide complete person and parental details\n4. Upload PSA of the person on the record\n5. Select payment method (Cash or GCash: 0997 742 8176)\n6. Submit and save Reference Number (REQ-2026-XXXX)\n7. Download signed PDF or pick up upon completion',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 310 OR `topic` = 'KB-33 How to request a certificate online';

-- 3. Update KB-34: Requesting on behalf of someone else
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'A representative must present a signed authorization letter and the representative\'s own valid government ID, in addition to the standard required document (PSA of the person on the record). Certificates are released only to the owner or an authorized representative to protect privacy. Tugon AI cannot share personal records directly in chat.',
  `steps` = '• Signed Authorization Letter from the record owner\n• Valid government ID of the representative\n• PSA of the person on the record',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 311 OR `topic` = 'KB-34 Requesting on behalf of someone else';

-- 4. Update Overview: Certificates Overview & Requirements (ID 201)
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'To request an official parish certificate (Baptismal, Confirmation, First Communion, Marriage, or Death), you will need: PSA of the person on the record, complete name of the person on the record, approximate date of the sacrament, and parents\' names. The processing fee is ₱100.00 per copy, taking 1 to 3 working days.',
  `steps` = '1. Open Certificate Requests (users/request-certificate.php)\n2. Select the specific certificate type (Baptismal, Confirmation, First Communion, Marriage, Death)\n3. Provide complete person and parental details\n4. Attach PSA of the person on the record\n5. Submit online or claim at the Parish Office (Tue-Sat 8:00 AM - 5:00 PM, Sun 7:00 AM - 12:00 PM)\n\n[Request Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 201 OR `topic` = 'Certificates Overview & Requirements';

-- 5. Update Baptismal Certificate (ID 202)
UPDATE `chatbot_knowledge`
SET 
  `steps` = '1. Open Certificate Requests (users/request-certificate.php)\n2. Select Baptismal Certificate\n3. Input child\'s full name, birth date, and parents\' names\n4. Upload PSA of the person on the record\n5. Submit request and save Reference Number\n\n[Request Baptism Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 202 OR `topic` = 'Baptismal Certificate';

-- 6. Update Marriage Certificate (ID 203)
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'Para sa Sertipiko ng Kasal (Marriage Certificate), kailangan ang PSA ng taong nasa talaan (PSA of the person on the record), buong pangalan ng mag-asawa (groom at maiden name ng bride), petsa ng kasal sa simbahan, at layunin ng request. Ang bayad ay ₱100.00 bawat kopya (1 hanggang 3 araw ng trabaho).',
  `steps` = '1. Buksan ang Certificate Requests (users/request-certificate.php)\n2. Piliin ang Marriage Certificate\n3. Ilagay ang mga pangalan ng mag-asawa at petsa ng kasal sa simbahan\n4. Mag-upload ng PSA ng taong nasa talaan (PSA of the person on the record)\n5. Isumite online o personal na magtungo sa Parish Office (Martes–Sabado 8:00 AM–5:00 PM, Linggo 7:00 AM–12:00 PM)\n\n[Request Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 203 OR `topic` = 'Marriage Certificate';

-- 7. Update Confirmation Certificate (ID 204)
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'To request a Confirmation Certificate, provide the confirmand\'s full name, approximate year of confirmation, parents\' names, and the PSA of the person on the record. The fee is ₱100.00 per copy with a 1 to 3 working day release.',
  `steps` = '1. Open Certificate Requests (users/request-certificate.php)\n2. Choose Confirmation Certificate\n3. Fill in confirmand and parent details\n4. Upload PSA of the person on the record\n5. Submit and track via My Requests\n\n[Request Confirmation Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 204 OR `topic` = 'Confirmation Certificate';

-- 8. Update Death Certificate (ID 205)
UPDATE `chatbot_knowledge`
SET 
  `answer` = 'To request an official Parish Death / Funeral Certificate, present the PSA of the person on the record (or official PSA / Local Civil Registrar Death Certificate), full name of the deceased, date of passing and funeral, and purpose of request. Fee is ₱100.00 per copy.',
  `steps` = '1. Go to Certificate Requests (users/request-certificate.php)\n2. Select Death Certificate\n3. Provide deceased person\'s complete records\n4. Attach PSA of the person on the record\n5. Submit online or at the parish office\n\n[Request Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 205 OR `topic` = 'Death Certificate';

-- 9. Update Requesting for Another Family Member (ID 213)
UPDATE `chatbot_knowledge`
SET 
  `steps` = '1. Enter the family member\'s baptismal/sacramental details in the form\n2. Upload the PSA of the person on the record\n3. Bring Authorization Letter when claiming at the office\n\n[Request Certificate](../users/request-certificate.php)',
  `updated_at` = CURRENT_TIMESTAMP
WHERE `knowledge_id` = 213 OR `topic` = 'Requesting for Another Family Member';
