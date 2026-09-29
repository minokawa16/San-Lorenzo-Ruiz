-- Canonical Migration 038: Chatbot Intent Routing Safeguards and Knowledge Enhancements
-- Adds detected_intent auditing and canonical entries for certificates, confession, and blessing policies.

ALTER TABLE ai_responses
  ADD COLUMN detected_intent VARCHAR(60) NULL AFTER provider,
  ADD KEY idx_ai_responses_intent (detected_intent);

ALTER TABLE chatbot_inquiries
  ADD COLUMN detected_intent VARCHAR(60) NULL AFTER mode,
  ADD KEY idx_chatbot_inquiries_intent (detected_intent);

REPLACE INTO chatbot_knowledge (
    knowledge_id,
    topic,
    keywords,
    answer,
    steps,
    category,
    source,
    status,
    approval_status,
    version,
    effective_date,
    language,
    reviewed_at,
    content_hash
) VALUES
(201, 'Certificates Overview & Requirements',
 'certificates,certificate,sertipiko,papeles,katibayan,pamatuod,requirements on how to get the certificates,how to get certificate,paano kumuha ng sertipiko,ano ang kailangan sa sertipiko,certificate requirements,general certificate requirements',
 'To request an official parish certificate (Baptismal, Confirmation, Marriage, or Death), you will need: a valid government ID, complete name of the person on the record, approximate date of the sacrament, and parents\' names. The processing fee is ₱100.00 per copy, taking 1 to 3 working days.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select the specific certificate type (Baptismal, Confirmation, Marriage, Death)\n3. Provide complete person and parental details\n4. Attach a valid ID or PSA document\n5. Submit online or claim at the Parish Office (Tue-Sat 8:00 AM - 5:00 PM, Sun 7:00 AM - 12:00 PM)\n\n[Request Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('cert_overview_canonical', 256)),

(202, 'Baptismal Certificate',
 'baptismal certificate,baptism certificate,sertipiko ng binyag,papeles ng binyag,pamatuod sa bunyag,how do i get a baptismal certificate for my child,kumuha ng baptismal certificate,paano kumuha ng baptismal certificate',
 'To obtain a Baptismal Certificate, submit a copy of the child\'s PSA or Civil Registrar Birth Certificate, full name of child, date of birth, and parents\' names. The processing fee is ₱100.00 per copy with a processing time of 1 to 3 working days.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select Baptismal Certificate\n3. Input child\'s full name, birth date, and parents\' names\n4. Upload PSA Birth Certificate copy and requester\'s Valid ID\n5. Submit request and save Reference Number\n\n[Request Baptism Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('baptism_cert_canonical', 256)),

(203, 'Marriage Certificate',
 'marriage certificate,wedding certificate,sertipiko ng kasal,papeles ng kasal,kasulatan sa kasal,sertipiko ng kasal ano kailangan,marriage cert requirements,how to get marriage certificate',
 'Para sa Sertipiko ng Kasal (Marriage Certificate), kailangan ang Valid ID ng humihiling (o Authorization Letter kung kinatawan), buong pangalan ng mag-asawa (groom at maiden name ng bride), petsa ng kasal sa simbahan, at layunin ng request. Ang bayad ay ₱100.00 bawat kopya (1 hanggang 3 araw ng trabaho).',
 '1. Buksan ang Certificate Requests (users/request-certificate.php)\n2. Piliin ang Marriage Certificate\n3. Ilagay ang mga pangalan ng mag-asawa at petsa ng kasal sa simbahan\n4. Mag-upload ng Valid ID ng humihiling\n5. Isumite online o personal na magtungo sa Parish Office (Martes–Sabado 8:00 AM–5:00 PM, Linggo 7:00 AM–12:00 PM)\n\n[Request Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('marriage_cert_canonical', 256)),

(204, 'Confirmation Certificate',
 'confirmation certificate,sertipiko ng kumpil,papeles ng kumpil,magkano ang confirmation certificate,kumpil certificate,confirmation cert',
 'To request a Confirmation Certificate, provide the confirmand\'s full name, approximate year of confirmation, copy of PSA Birth Certificate or Baptismal Certificate, and a valid ID. The fee is ₱100.00 per copy with a 1 to 3 working day release.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Choose Confirmation Certificate\n3. Fill in confirmand and parent details\n4. Upload supporting document (PSA or Baptism cert)\n5. Submit and track via My Requests\n\n[Request Confirmation Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('confirmation_cert_canonical', 256)),

(205, 'Death Certificate',
 'death certificate,funeral certificate,sertipiko ng patay,papeles ng yumao,sertipiko ng libing,katibayan ng pagpanaw',
 'To request an official Parish Death / Funeral Certificate, present a copy of the official PSA or Local Civil Registrar Death Certificate, full name of the deceased, date of passing and funeral, and valid ID of the immediate family requester. Fee is ₱100.00 per copy.',
 '1. Go to Certificate Requests (users/request-certificate.php)\n2. Select Death Certificate\n3. Provide deceased person\'s complete records\n4. Attach civil death certificate copy\n5. Submit online or at the parish office\n\n[Request Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('death_cert_canonical', 256)),

(206, 'Confession and Reconciliation Schedule',
 'confession schedule,what time is confession,confession,kumpisal,kompisal,oras ng kumpisal,kailan ang kumpisal,unsang orasa ang kumpisal,sacrament of reconciliation,penance,mangumpisal',
 'The Sacrament of Reconciliation (Confession) is heard every Wednesday and Friday from 4:30 PM to 5:15 PM (before evening Mass) and every Saturday from 4:00 PM to 5:00 PM at the Confessional Area near the Sacred Heart Shrine. For sick calls or urgent confession, appointment may be made at the Parish Office.',
 '• Wednesday & Friday: 4:30 PM - 5:15 PM\n• Saturday: 4:00 PM - 5:00 PM\n• Location: Confessional Area near the Sacred Heart Shrine\n• Urgent / Sick calls: Coordinate with Parish Office (Agnes C. Calapaan: 0997 742 8176)\n\n[View Schedule](../users/view-schedule.php)',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('confession_schedule_canonical', 256)),

(207, 'House and Vehicle Blessing Fee Policy',
 'how much is a house blessing,house blessing fee,blessing offering,bayad sa basbas ng bahay,magkano ang house blessing,vehicle blessing fee,car blessing fee,how much is a vehicle blessing,love offering,blessing donation',
 'For a House Blessing or Vehicle Blessing, there is no mandatory fixed fee. The parish welcomes any voluntary offering or free-will donation (love offering) for the officiating priest and parish ministry. Please book at least 1 week in advance.',
 '1. Go to Request Blessing (users/request-blessing.php)\n2. Choose House Blessing or Vehicle Blessing\n3. Provide complete location address/landmark or vehicle details\n4. Select your preferred date and time\n5. Submit for parish priest scheduling\n\n[Request Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('blessing_fee_canonical', 256));
