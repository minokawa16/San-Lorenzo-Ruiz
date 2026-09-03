-- Canonical Migration 016: Add Extended 80-Question Knowledge Base to chatbot_knowledge
-- Covers all operational, technical, sacramental, policy, browser, and AI guidance questions.

REPLACE INTO chatbot_knowledge (knowledge_id, topic, keywords, answer, steps, category, source, status, approval_status, version, effective_date, language, reviewed_at, content_hash) VALUES
(201, 'Marriage Certificate Request Online', 'request marriage certificate,marriage cert,sertipiko ng kasal,marriage certificate online,kumuha ng marriage certificate',
 'Yes, you can request an official Marriage Certificate through TUGON. Open Certificate Requests, select Marriage Certificate, enter the bride and groom names and date of marriage, upload a valid ID or PSA copy, and submit.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select "Marriage Certificate"\n3. Enter husband and wife full names and marriage date\n4. Attach Valid ID or PSA document\n5. Submit request\n\n[Request Marriage Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('marriage_cert_online_faq', 256)),

(202, 'What Completed Status Means', 'what does completed mean,completed status,ano ang completed,released status,completed request',
 'Completed (or Released) status means your physical certificate has been officially claimed and issued at the parish office, or your sacramental service has been successfully celebrated.',
 '• Certificates: Physical certificate claimed at the parish office\n• Services: Sacramental service or blessing completed\n• Transaction is finalized and archived in your history.\n\n[View My Requests](../users/my-requests.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('completed_status_faq', 256)),

(203, 'Can I Edit Submitted Request', 'can i edit request,edit submitted request,baguhin ang request,palitan ang request,modify request',
 'If your request is still in Pending status, you can contact the parish office with your Reference Number or cancel and submit a corrected request. Once in processing, details cannot be edited directly in the portal.',
 '1. For Pending requests: Check details in [My Requests](../users/my-requests.php)\n2. Contact Parish Secretary at 0997 742 8176 for adjustments before printing.',
 'requests', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('edit_request_faq', 256)),

(204, 'Can I Cancel a Submitted Request', 'cancel request,cancel submitted request,i-cancel ang request,kanselahin ang request,cancel reservation',
 'Yes. If your request or reservation is still in Pending review, open its details page in My Requests (users/my-requests.php) and click Cancel Request.',
 '1. Open My Requests (users/my-requests.php)\n2. Click on the Pending request\n3. Click "Cancel Request"\n\n[My Requests](../users/my-requests.php)',
 'requests', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('cancel_request_faq', 256)),

(205, 'Business Blessing Request', 'business blessing,blessing ng tindahan,blessing ng opisina,pabasbas ng negosyo,request business blessing',
 'To request a Business Blessing, open Request Blessing, choose Business Blessing, enter your commercial establishment name, complete address, preferred date and time, and submit for priest assignment.',
 '1. Open Request Blessing (users/request-blessing.php)\n2. Select "Business Blessing"\n3. Enter establishment name, address, and landmark\n4. Choose target date and time\n5. Submit request\n\n[Request Business Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('business_blessing_faq', 256)),

(206, 'Choosing Preferred Date and Time', 'choose preferred date and time,select schedule,pumili ng schedule,preferred time,parish service schedule',
 'Yes, you can choose your preferred date and timeslot from the interactive calendar when booking a sacramental service or blessing. The calendar will automatically display available slots.',
 '1. Open Request Service (users/request-service.php) or Request Blessing (users/request-blessing.php)\n2. Browse open calendar slots\n3. Select your desired date and time\n\n[Request Service](../users/request-service.php)',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('choose_schedule_faq', 256)),

(207, 'What If Preferred Schedule Is Unavailable', 'preferred schedule unavailable,fully booked,conflict schedule,walang bakanteng schedule,reschedule slot',
 'If a timeslot is already booked, the calendar will prevent duplicate reservations. Please pick an alternative open date/time on the calendar, or contact the parish office for special arrangements.',
 'Contact Parish Secretary at 0997 742 8176 for scheduling inquiries.\n[View Calendar](../users/view-schedule.php)',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('schedule_unavailable_faq', 256)),

(208, 'Blurry Document Resolution', 'blurry document,malabong dokumento,unreadable file,blurry id,re-upload blurry doc',
 'If your uploaded document is blurry, open your request in My Requests (users/my-requests.php) or submit a replacement with a well-lit, high-resolution photo (JPG, PNG) or scanned PDF showing all 4 corners.',
 '1. Take a well-lit photo in natural light without glare\n2. Make sure all text and seals are legible\n3. Upload via [My Requests](../users/my-requests.php) or contact the parish office.',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('blurry_doc_faq', 256)),

(209, 'Uploading Supplemental Documents After Submission', 'upload another document,upload after submit,mag-upload muli,additional documents,re-upload file',
 'Yes. If your request is Pending or if the parish office added an admin remark requesting additional files, you can upload supplemental documents directly from your request details page.',
 '1. Open My Requests (users/my-requests.php)\n2. Click on the request\n3. Upload the requested file under the Requirements section\n\n[My Requests](../users/my-requests.php)',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('upload_after_submit_faq', 256)),

(210, 'Confirming Document Upload Success', 'how do i know document uploaded,document successfully uploaded,upload confirmation,uploaded checkmark',
 'When your file is successfully uploaded, you will see a green checkmark icon, the filename, and an interactive preview thumbnail in the document section.',
 'Check your uploaded files: [My Requests](../users/my-requests.php)',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('upload_success_faq', 256)),

(211, 'Why TUGON Requires Valid ID', 'why valid id required,bakit kailangan ng valid id,purpose of valid id,security valid id',
 'TUGON requires a valid ID to protect sensitive sacramental records, verify identity in compliance with Data Privacy standards, and ensure certificates are released only to authorized individuals.',
 '• Prevents identity theft and fraudulent requests\n• Ensures canonical records are released to rightful owners\n• Secure OCR identity verification.',
 'security', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('why_valid_id_faq', 256)),

(212, 'Requesting Without Required Documents', 'submit without document,puwede bang walang dokumento,no document request,missing requirements',
 'No. Mandatory supporting documents (such as PSA Birth Certificates or Valid IDs) are required before a request can be submitted to ensure parish records can be verified against church books.',
 'Prepare your files and submit: [Request Certificate](../users/request-certificate.php)',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('no_document_faq', 256)),

(213, 'Requesting for Another Family Member', 'request for another person,request for family member,kumuha para sa iba,authorization letter,representative request',
 'Yes, you can request a certificate for an immediate family member (e.g., parents for their children). When claiming, please present an Authorization Letter and Valid IDs of both the requester and the owner.',
 '1. Enter the family member\'s baptismal/sacramental details in the form\n2. Upload their PSA copy and your valid ID\n3. Bring Authorization Letter when claiming at the office\n\n[Request Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('family_request_faq', 256)),

(214, 'Name Different from Parish Record', 'name different from record,mali ang pangalan,discrepancy in name,spelling error,wrong name in book',
 'If your name differs from the parish registry book (e.g. spelling error or maiden name), please present an official PSA Birth Certificate, Baptismal Certificate, or an Affidavit of One and the Same Person to the parish office.',
 'Contact Parish Secretary at 0997 742 8176 for canonical record verification.',
 'records', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('name_discrepancy_faq', 256)),

(215, 'Sacramental Record Cannot Be Found', 'record cannot be found,hindi mahanap ang record,missing sacramental record,no record found,archive search',
 'If your record cannot be located online, contact the Parish Secretary (Agnes C. Calapaan at 0997 742 8176). Staff will conduct an in-depth manual search through the physical archival registry books.',
 '📞 Contact Parish Secretary: 0997 742 8176\n🕒 Office Hours: Tuesday - Saturday (8:00 AM - 5:00 PM)',
 'records', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('record_not_found_faq', 256)),

(216, 'Why Administrator Approval Is Needed for Registration', 'why admin approval registration,bakit kailangan ng admin approval,account approval,registration verification',
 'Administrator approval for new registrations ensures that every account belongs to a verified parishioner with a valid government ID, keeping the platform secure from spam and unauthorized access.',
 '• Verifies authentic parishioner identity\n• OCR and facial verification validation\n• You will receive SMS/email notice upon activation.',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('admin_approval_reg_faq', 256)),

(217, 'What If Registration Is Rejected', 'registration rejected,na-reject ang registration,rejected account,re-register rejected',
 'If your registration is rejected, check the rejection notice sent via email/SMS for the reason (e.g. unreadable ID or blurry selfie), and register again with a clear valid government ID.',
 'Register again: [Create Account](../auth/register.php)',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('registration_rejected_faq', 256)),

(218, 'Why Did I Not Receive Notification', 'did not receive notification,walang natanggap na text,no email received,missing notification',
 'If you did not receive a notification: 1) Check your spam/junk email folder, 2) Verify your mobile number in Profile Settings, or 3) View real-time updates directly under the 🔔 Bell icon in TUGON.',
 '1. Check spam/junk email folder\n2. Verify mobile number in [Profile Settings](../auth/profile.php)\n3. View alerts in [Notifications](../users/notifications.php)',
 'notifications', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('no_notification_faq', 256)),

(219, 'Check Request Without Notification', 'check request without notification,tingnan kahit walang text,manual check request status',
 'Yes! You can always check your live request status anytime by logging in and opening My Requests (users/my-requests.php).',
 'Open [My Requests](../users/my-requests.php) to see live progress timelines 24/7.',
 'tracking', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('check_without_notification_faq', 256)),

(220, 'Multiple Certificate Requests and Reservations', 'multiple requests,more than one request,multiple reservations,maraming request,sabay-sabay na request',
 'Yes! You can submit more than one certificate request or active reservation at the same time. Each submission receives its own unique Reference Number for independent tracking.',
 'Submit another: [Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)',
 'requests', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('multiple_requests_faq', 256)),

(221, 'What to Do After Certificate Is Approved', 'what to do after approved,approved certificate next steps,ano ang gagawin pagkatapos ma-approve',
 'Once approved, your document enters processing. When the status updates to Ready for Pickup, visit the parish office with 1 valid ID and your Reference Number to claim your official signed and sealed certificate.',
 '1. Wait for "Ready for Pickup" status notification\n2. Bring 1 Valid ID and Reference Number to the Parish Office\n3. Claim official certificate\n\n[Track My Requests](../users/my-requests.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('after_approved_faq', 256)),

(222, 'Can I Download Certificate from TUGON', 'can i download certificate,download certificate,pdf certificate download,i-download ang sertipiko',
 'Official Catholic sacramental certificates must bear the original pen signature of the Parish Priest and the parish embossed dry seal, so they must be claimed physically at the parish office.',
 '• Physical pickup ensures legal and canonical authenticity\n• Bring Valid ID and Reference Number to claim.\n\n[Track My Requests](../users/my-requests.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('download_cert_faq', 256)),

(223, 'Incorrect Information on Released Certificate', 'incorrect information on certificate,mali ang nakasulat sa certificate,wrong details on printed cert',
 'If you notice an error on your released certificate, bring it back to the parish office along with your PSA Birth Certificate. The office will verify the canonical book and issue a corrected copy.',
 'Visit the parish office or call Parish Secretary at 0997 742 8176.',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('incorrect_cert_faq', 256)),

(224, 'Request Correction to Parish Canonical Record', 'request correction parish record,tamang tala sa libro,canonical record correction,correct baptism record',
 'To correct an entry in the parish registry book, submit an official Request for Record Correction at the parish office accompanied by your PSA Birth Certificate, Affidavit, and sacramental documents.',
 'Contact Parish Secretary at 0997 742 8176 for the record correction procedure.',
 'records', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('record_correction_faq', 256)),

(225, 'Mobile Compatibility and Supported Browsers', 'mobile phone,cellphone,browsers,chrome,safari,firefox,edge,gumagana ba sa cellphone',
 'Yes, TUGON is fully optimized for mobile devices, tablets, and desktop computers. You can use Google Chrome, Safari, Mozilla Firefox, Microsoft Edge, or Opera.',
 '• Supported on Android, iOS, Windows, macOS\n• Recommended: Google Chrome or Apple Safari',
 'technical', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('mobile_browsers_faq', 256)),

(226, 'Troubleshooting Loading and Connection Issues', 'not loading,lost connection,hindi naglo-load,nawalan ng internet,connection lost,troubleshoot',
 'If TUGON is not loading: 1) Refresh the page, 2) Clear browser cache and cookies, or 3) Check your internet connection. If you lose connection during submission, check My Requests (users/my-requests.php) upon reconnecting to see if your request went through.',
 '1. Refresh your browser (Ctrl+F5 or pull to refresh on mobile)\n2. Check [My Requests](../users/my-requests.php) to confirm submission status\n3. Contact support if issue persists.',
 'technical', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('troubleshoot_faq', 256)),

(227, 'Accidental Duplicate Submissions', 'duplicate submission,submitted twice,nag-submit ng dalawang beses,nadobleng request',
 'If you accidentally submitted the same request twice, open My Requests (users/my-requests.php) and click Cancel Request on the duplicate, or inform the Parish Secretary.',
 'Cancel duplicate: [My Requests](../users/my-requests.php)',
 'requests', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('duplicate_submission_faq', 256)),

(228, 'AI Assistant Authority vs Parish Secretary', 'ai vs parish secretary,which information should i follow,sino ang susundin ai o secretary,authority difference',
 'Always follow the **Parish Secretary (Agnes C. Calapaan)** and **Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)**. The clergy and parish office staff are the official human canonical authorities; the AI Assistant is a helpful informational guide.',
 '• Human Authority: Parish Priest & Parish Secretary (Official & Binding)\n• TUGON AI: Informational navigation and 24/7 inquiry assistant',
 'ai', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('authority_faq', 256));

REPLACE INTO chatbot_knowledge_meta (meta_key, meta_value)
VALUES ('official_dataset_version', '2026-09-03-extended-80faq-v1');
