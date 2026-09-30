-- Canonical Migration 039: Official TUGON AI Knowledge Base (San Lorenzo Ruiz Parish · Aleosan, Cotabato)
-- Chunks KB-01 through KB-93 from the official Knowledge Base specification

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
-- B1. About the system and roles
(301, 'KB-01 What is TUGON and Tugon AI',
 'tugon,what is this,portal,system,online,website,tugon ai,ano ang tugon,tungkol sa tugon,ano ang tugon ai',
 'TUGON Parish Management Information System is the parish\'s online portal. Parishioners use it to register, request certificates, sacramental services, and blessings, check the parish calendar, track requests, and download released certificates. Tugon AI is the virtual assistant that answers questions about these services. Portal: https://tugon-parish-system.vercel.app',
 '1. Open the portal: https://tugon-parish-system.vercel.app\n2. Register and complete account verification\n3. Access certificate requests, sacramental services, blessings, and the parish calendar',
 'general', 'TUGON KB-01', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_01_tugon_ai', 256)),

(302, 'KB-02 Who does what',
 'roles,secretary,priest,staff,who approves,sino nag-aapruba,tungkulin,sinong gumagawa',
 'Parishioner/Member registers an account, submits requests, tracks status, views schedule, and downloads released certificates. Parish Secretary/Staff reviews registrations, checks IDs and payment receipts, encodes church registers, schedules calendar slots, and issues certificates. Parish Priest/Clergy reviews marriage interviews, approves sacramental rites, signs certificates, and officiates liturgies. Administrator manages system settings and roles.',
 '• Parishioner: submits requests, tracks progress, downloads certificates\n• Secretary/Staff: reviews documents & receipts, encodes registers, issues certificates\n• Priest: canonical interviews, liturgical approvals, signs certificates',
 'office', 'TUGON KB-02', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_02_roles', 256)),

-- B2. Account registration and verification
(303, 'KB-10 How to register and get verified',
 'register,sign up,create account,verification,verify,magparehistro,mag-register,account,approve account,paano magrehistro,mag-sign up',
 'To protect parish records, every parishioner must complete verified registration before using services. You need: full legal name, active mobile number, active email, complete address and your Chapel/GKK/BEC, one valid government ID (Driver\'s License, Passport, PhilID/National ID, UMID, Postal ID, PRC ID, Voter\'s ID, or SSS ID, max 5 MB), and a live selfie taken during registration.',
 '1. Fill in your details on the registration page\n2. Upload your ID and take a live selfie (system reads details automatically)\n3. Registration goes to parish staff for review (Pending Review)\n4. Staff verifies photo, selfie, and address\n5. Once approved, you receive an SMS and email confirming activation\n6. If blurry or invalid, staff rejects it with remarks so you can resubmit',
 'account', 'TUGON KB-10', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_10_register_verify', 256)),

(304, 'KB-11 Registration rejected or not yet approved',
 'rejected registration,not approved,waiting,blurry,cannot login,hindi ma-approve,na-reject ang registration,bakit hindi ma-approve',
 'If your registration is rejected or pending: Read the remarks in the SMS or email. The most common reasons are a blurry or cropped ID photo, a selfie that does not match, an unsupported ID, or an address mismatch. Resubmit with a clear, uncropped, well-lit photo of a valid government ID and a clear selfie facing the camera. Staff reviews during office hours. If delayed, contact the parish office at 0997 742 8176.',
 '1. Check the remarks in your SMS or email\n2. Take a clear, uncropped photo of your valid ID\n3. Take a well-lit selfie directly facing the camera\n4. Resubmit your registration\n5. For assistance, contact the office at 0997 742 8176 (Tue-Sat 8AM-5PM, Sun 7AM-12PM)',
 'account', 'TUGON KB-11', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_11_registration_rejected', 256)),

-- B3. Request statuses
(305, 'KB-20 The four request statuses',
 'status,pending,processing,completed,rejected,tracking,my requests,reference number,ano na status,kahulugan ng status,request status',
 'All requests use four statuses: Pending (Amber - Under initial review, verifying documents and payment; wait and ensure uploads are complete), Processing (Blue - Being coordinated or encoded, checking registry books or priest coordination; wait for notification), Completed (Green - Finished, signed PDF downloadable in My Requests or ready for pickup at office), Rejected (Red - Declined or needs correction; read admin remarks, fix the issue, and resubmit). A rejected request can be resubmitted and returns to Pending.',
 '• Pending (Amber): Office received request and is verifying documents and payment\n• Processing (Blue): Encoding certificate, checking church books, or coordinating priest\n• Completed (Green): Finished; download signed PDF in My Requests or pick up at office\n• Rejected (Red): Needs correction; read remarks in My Requests, fix, and resubmit',
 'status', 'TUGON KB-20', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_20_four_statuses', 256)),

(306, 'KB-21 My request was rejected',
 'rejected,declined,bakit na-reject,resubmit,correction,na-reject ang request,paano mag-resubmit,admin remarks',
 'If your request was rejected: Open My Requests and read the admin remarks. They state the exact reason (incomplete or unclear documents, missing payment receipt, schedule conflict, or record not found in registers). Fix what the remarks ask for and resubmit. If record was not found, double-check name spelling, approximate year, and parents\' names (including mother\'s maiden name). If remarks are unclear, call 0997 742 8176.',
 '1. Log in and open My Requests (users/my-requests.php)\n2. Read the admin remarks explaining the reason\n3. Correct the specified document, info, or payment receipt\n4. Click Resubmit (request returns to Pending status)\n5. For questions, call the parish office at 0997 742 8176',
 'status', 'TUGON KB-21', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_21_request_rejected', 256)),

-- B4. Certificate requests
(307, 'KB-30 Certificates available',
 'certificate,certificates,sertipiko,papeles,types,uri ng sertipiko,available certificates,mga sertipiko',
 'The parish issues 6 official certificate types: 1. Baptismal Certificate (sertipiko ng binyag), 2. Confirmation Certificate (sertipiko ng kumpil), 3. First Communion Certificate (sertipiko ng unang komunyon), 4. Marriage Certificate (sertipiko ng kasal), 5. Death / Funeral Certificate (sertipiko ng libing / pagpanaw), 6. Good Moral / Parish Certification (katibayan ng mabuting asal).',
 'Available Certificate Types:\n1. Baptismal Certificate\n2. Confirmation Certificate\n3. First Communion Certificate\n4. Marriage Certificate\n5. Death / Funeral Certificate\n6. Good Moral / Parish Certification',
 'certificates', 'TUGON KB-30', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_30_certs_available', 256)),

(308, 'KB-31 Certificate requirements',
 'requirements certificate,ano kailangan,kinakailangan,how to get certificate,papeles,certificate requirements,mga kailangan sa sertipiko',
 'Requirements for certificates: Valid government ID of requester; Authorization letter + ID of representative (if requesting for someone else); PSA or Local Civil Registrar copy of Birth/Marriage/Death Certificate (to cross-check data); Record details: full name of the person on the record, approximate date or year of sacrament, parents\' full names (including mother\'s maiden name), and purpose of request (First Communion, Confirmation, Marriage Preparation, School, Passport/Travel, Employment, Legal/Personal, or Burial/Benefits).',
 'General Requirements for Parish Certificates:\n• Valid government ID of requester\n• Authorization letter + representative ID (if claiming for another)\n• PSA / Civil Registrar certificate copy\n• Person\'s full name, approximate year of sacrament, and parents\' full names\n• Specific purpose of request',
 'certificates', 'TUGON KB-31', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_31_cert_requirements', 256)),

(309, 'KB-32 Certificate fee, processing time, payment',
 'magkano,fee,price,cost,bayad,payment,gcash,processing time,gaano katagal,magkano sertipiko,bayad sa certificate',
 'Certificate fee is ₱100.00 per copy. Processing time typically takes 1 to 3 working days. Payment options: 1. Cash at the Parish Office (cash on pick-up); 2. GCash: send to Agnes Calapaan (Parish Secretary) at 0997 742 8176, then enter the GCash reference number and upload a screenshot of the receipt in the request form.',
 '• Fee: ₱100.00 per copy\n• Processing Time: typically 1 to 3 working days\n• Payment: Cash on pick-up OR GCash to Agnes Calapaan (0997 742 8176)\n• For GCash: enter the reference number and upload the receipt screenshot in the portal',
 'certificates', 'TUGON KB-32', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_32_cert_fee_processing', 256)),

(310, 'KB-33 How to request a certificate online',
 'how to request certificate,paano mag-request,steps,online,paano mag-request ng sertipiko,steps certificate',
 'To request a certificate online: 1. Log in to the TUGON portal; 2. Open Request Certificate; 3. Choose Certificate Type and Purpose; 4. Enter the person\'s complete details and approximate year of sacrament; 5. Upload supporting documents (valid ID and PSA copy); 6. Choose payment method (GCash or cash on pick-up; for GCash, enter reference number and upload receipt); 7. Submit and save your reference number (REQ-2026-XXXX); 8. Track progress in My Requests; 9. When Completed, download signed PDF or pick it up at the office.',
 '1. Log in to TUGON (users/request-certificate.php)\n2. Choose Certificate Type and Purpose\n3. Provide complete person and parental details\n4. Upload Valid ID and PSA copy\n5. Select payment method (Cash or GCash: 0997 742 8176)\n6. Submit and save Reference Number (REQ-2026-XXXX)\n7. Download signed PDF or pick up upon completion',
 'certificates', 'TUGON KB-33', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_33_request_online', 256)),

(311, 'KB-34 Requesting on behalf of someone else',
 'representative,authorization letter,for my child,for my mother,kahit sino,kinatawan,kumuha para sa iba,authorization',
 'A representative must present a signed authorization letter and the representative\'s own valid government ID, in addition to the standard required documents (PSA copy and valid ID of the record owner). Certificates are released only to the owner or an authorized representative to protect privacy. Tugon AI cannot share personal records directly in chat.',
 '• Signed Authorization Letter from the record owner\n• Valid government ID of the representative\n• Valid government ID of the record owner\n• PSA / Civil Registrar copy of the record',
 'certificates', 'TUGON KB-34', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_34_representative', 256)),

(312, 'KB-35 Certificate for marriage purposes',
 'for marriage purposes,annotation,kasal,pre-cana,marriage purpose,tatak para sa kasal,validity 6 months',
 'For a church wedding, Baptismal and Confirmation certificates must carry the official annotation "For Marriage Purposes" and be issued within the last 6 months. When requesting online, select "Marriage Preparation" as the purpose so the certificate is printed with the correct annotation.',
 '• Annotation: Must state "For Marriage Purposes"\n• Validity: Issued within the last 6 months\n• How to request: Choose "Marriage Preparation" as purpose in Request Certificate form',
 'certificates', 'TUGON KB-35', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_35_marriage_purposes', 256)),

-- B5. Sacramental services
(313, 'KB-40 Holy Matrimony / Church Wedding',
 'wedding,kasal,marriage,matrimony,ikasal,church wedding,requirements wedding,kailan ikasal,kasal sa simbahan,requirements for wedding',
 'Holy Matrimony / Church Wedding: File at least 2 to 3 months before wedding date. Required documents: PSA Birth Certificates (groom & bride); PSA CENOMAR (issued within last 6 months); Updated Baptismal Certificate annotated "For Marriage Purposes" (within 6 months); Updated Confirmation Certificate annotated "For Marriage Purposes"; Pre-Cana Marriage Seminar Certificate; Canonical Interview with Parish Priest; Publication of Marriage Banns (3 consecutive Sundays in both home parishes); Civil Marriage License (or Art. 34 Affidavit of Cohabitation if living together 5+ years); BEC/Chapel recommendation; CO Permit to Marry (if military/police); Principal Male Sponsor (Ninong) & Female Sponsor (Ninang) names and origins; preferred date, time slot, and venue. Wedding fees are not published online; refer to the parish office.',
 'Filing Lead Time: at least 2 to 3 months ahead\nChecklist:\n• PSA Birth Certificates & CENOMAR (within 6 mos)\n• Baptismal & Confirmation Certs annotated "For Marriage Purposes" (within 6 mos)\n• Pre-Cana Seminar Certificate\n• Canonical Interview with Parish Priest\n• Marriage Banns (3 Sundays)\n• Marriage License or Article 34 Affidavit\n• BEC / Chapel Recommendation\n• CO Permit to Marry (military/police)\n• Principal Sponsors (Ninong & Ninang)\n• Fee: Contact Parish Office (0997 742 8176)',
 'sacraments', 'TUGON KB-40', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_40_wedding', 256)),

(314, 'KB-41 Baptism (child)',
 'baptism,binyag,pabunyag,baptize,ninong,ninang,godparent,seminar,pabinyag,requirements baptism,binyag requirements',
 'Child Baptism: Register at least 1 to 2 weeks before baptism date. Documents required: Photocopy of child\'s PSA or Civil Registrar Live Birth Certificate with registry number; Photocopy of parents\' Catholic Church Marriage Certificate (if church-married); Chapel recommendation from local GKK/Chapel leader; White Cards / seminar slips of parents and principal godparents; Attendance at Pre-Baptismal Seminar (scheduled Saturday mornings). Godparent rule: at least ONE fully initiated, practicing Catholic godparent (Ninong or Ninang) who has received Confirmation. Baptism fees are not published online; refer to the parish office at 0997 742 8176.',
 'Filing Lead Time: at least 1 to 2 weeks ahead\nChecklist:\n• PSA / Civil Registrar Live Birth Certificate (with registry number)\n• Parents\' Catholic Marriage Certificate (if church-married)\n• GKK / Chapel Leader Recommendation\n• White Cards / seminar slips of parents and godparents\n• Attendance in Saturday morning Pre-Baptismal Seminar\n• Godparent Rule: at least 1 confirmed, practicing Catholic sponsor\n• Fee: Contact Parish Office (0997 742 8176)',
 'sacraments', 'TUGON KB-41', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_41_baptism', 256)),

(315, 'KB-42 Funeral Mass and burial blessing',
 'funeral,libing,patay,namatay,burial,wake,death,yumao,misa sa patay,burol,padasal',
 'Funeral Mass and Burial Blessing: Coordinate with parish office immediately upon death. Needed: Official PSA or Local Civil Registrar Death Certificate; Cemetery Burial Permit and coordination time; Full name of deceased, date of birth, date of passing, and civil status; Place of burial / cemetery; Preferred Funeral Mass schedule (at parish church, or home wake blessing). For urgent coordination, call hotline 0997 742 8176 immediately. Respond with compassion first.',
 'Timing: Coordinate immediately with parish office\nRequired Details:\n• PSA / Civil Registrar Death Certificate\n• Cemetery Burial Permit\n• Deceased full name, birth date, date of passing, civil status\n• Cemetery / burial place\n• Preferred schedule (parish church Mass or home wake blessing)\n• Emergency Hotline: 0997 742 8176',
 'funeral', 'TUGON KB-42', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_42_funeral', 256)),

(316, 'KB-43 Anointing of the Sick',
 'anointing,pahid,masakit,sick,ospital,maysakit,last rites,dying,naghihingalo,pahid ng langis,sick call',
 'Anointing of the Sick: Available at any time for parishioners dangerously ill, preparing for major surgery, or of advanced age. Contact the Parish Emergency Hotline 0997 742 8176, or submit an Anointing of the Sick request in the portal for a priest visitation. Fee: voluntary offering only. If urgent, call the hotline now instead of waiting for an online request.',
 '• Availability: At any time (24/7 emergency)\n• Contact: Call Parish Emergency Hotline 0997 742 8176 immediately\n• Fee: Voluntary offering only\n• Needed: Name of sick person, exact location/hospital, contact person phone number',
 'sacraments', 'TUGON KB-43', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_43_anointing', 256)),

(317, 'KB-44 How to submit a sacramental service request',
 'how to request service,steps service,paano mag-request ng serbisyo,sacramental services steps,mag-apply ng sakramento',
 'To submit a sacramental service request: 1. Log in and open Sacramental Services; 2. Choose the service (Wedding, Baptism, Funeral Mass, or Anointing of the Sick); 3. Enter details and pick preferred date and time (system checks real-time availability); 4. Upload required documents; 5. Submit and save reference number; 6. Track in My Requests. Parish office and priest review and coordinate details.',
 '1. Log in to TUGON portal (users/request-service.php)\n2. Select service type (Wedding, Baptism, Funeral Mass, Anointing)\n3. Pick date & time (1-hour slot check)\n4. Upload supporting documents\n5. Submit and track via My Requests',
 'sacraments', 'TUGON KB-44', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_44_submit_service', 256)),

(318, 'KB-45 Fees for wedding, baptism, and funeral',
 'magkano kasal,magkano binyag,magkano libing,wedding fee,baptism fee,funeral fee,offering,stipend,bayad,pila bayad sa kasal,pila binyag',
 'The fees or offerings for weddings, baptisms, and funerals are not published online. Never quote or estimate an amount. Please contact the parish office at 0997 742 8176 (Tue to Sat 8:00 AM to 5:00 PM, Sun 7:00 AM to 12:00 PM, closed Monday). Certificates are ₱100.00 per copy; blessings and Anointing of the Sick take a voluntary offering only.',
 '• Wedding, Baptism, and Funeral fees: NOT published online\n• For inquiries, contact Parish Office: 0997 742 8176 (Tue-Sat 8AM-5PM, Sun 7AM-12PM)\n• Certificates: ₱100.00 per copy\n• Blessings & Anointing: Voluntary offering only',
 'office', 'TUGON KB-45', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_45_service_fees', 256)),

-- B6. Blessings
(319, 'KB-50 Blessing types',
 'blessing,basbas,pabasbas,types,house blessing,vehicle blessing,business blessing,uri ng basbas,pabasbas ng bahay,sasakyan',
 'Blessing types available: House Blessing (Basbas ng Bahay / Pamilya), Vehicle Blessing (Pabasbas ng Sasakyan / Motorsiklo), Business Blessing (Basbas ng Tindahan / Negosyo), Office / Institutional Blessing (Basbas ng Opisina), Event Blessing (Basbas ng Pagtitipon), and Other Special Blessings.',
 'Available Blessing Categories:\n1. House Blessing\n2. Vehicle Blessing (Car, Motorcycle)\n3. Business Blessing\n4. Office / Institutional Blessing\n5. Event Blessing\n6. Other Special Blessings',
 'blessings', 'TUGON KB-50', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_50_blessing_types', 256)),

(320, 'KB-51 Blessing offering / fee',
 'blessing fee,magkano basbas,offering,donation,bayad,bayad sa basbas,love offering,magkano pabasbas,libre ba ang basbas',
 'There is no mandatory fixed fee for blessings. Blessings are pastoral acts of the Church. Parishioners may give a voluntary free-will offering (love offering) according to their means, to support the priest and parish operations.',
 '• Fee: No mandatory fixed fee\n• Offering: Voluntary free-will love offering according to your means\n• Lead time: Submit request at least 1 week in advance',
 'blessings', 'TUGON KB-51', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_51_blessing_fee', 256)),

(321, 'KB-52 How to request a blessing',
 'how to request blessing,requirements blessing,paano magpabasbas,steps blessing,magpa-bless,paano mag-request ng basbas',
 'Blessing request lead time: submit at least 1 week in advance to avoid priest scheduling conflicts. Details required: Exact physical address with landmark directions (or vehicle plate number and model); Preferred date and time; Contact person\'s name and reachable mobile number. Steps: Log in, open Request Blessing, choose type, enter details, pick date/time (system checks availability), submit, save reference number, and track in My Requests.',
 '1. Lead time: Submit at least 1 week ahead\n2. Open Request Blessing (users/request-blessing.php)\n3. Provide exact address/landmark or vehicle details\n4. Select preferred date and 1-hour time slot\n5. Provide contact person name and mobile number\n6. Submit and track in My Requests',
 'blessings', 'TUGON KB-52', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_52_how_to_request_blessing', 256)),

-- B7. Schedule availability and conflicts
(322, 'KB-60 The 1-hour slot rule',
 'schedule,slot,available,occupied,conflict,not available,time,oras,puno,9:30,next available,reschedule,1-hour,one hour rule',
 'The 1-hour slot rule: Every booking (Mass, wedding, baptism, blessing, funeral, event) occupies one full hour: from start time to start time + 60 minutes. A new request conflicts if it overlaps any existing booking. Example: an existing booking at 9:00 AM blocks 9:00 to 10:00 AM. 8:00 AM is allowed. 9:00 AM and 9:30 AM are NOT available. 10:00 AM is the next available time. (The system never offers 9:30.) Choose times on the hour. Both approved and pending requests reserve their slot.',
 '• Slot Rule: Every booking occupies 1 full hour\n• Example: 9:00 AM booking blocks 9:00 AM to 10:00 AM\n• 9:30 AM is NOT available\n• 10:00 AM is the NEXT available time\n• Pending and approved requests both reserve their slot',
 'schedule', 'TUGON KB-60', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_60_1hr_slot_rule', 256)),

(323, 'KB-61 What happens when the slot is taken',
 'already occupied,submit disabled,cannot submit,bakit hindi ma-submit,conflict,nakareserba na,occupied slot',
 'When a slot is taken: The system checks in real time when you pick a date and time. Free slot: a green message confirms the slot is available, and you can submit. Occupied slot: a red message says the slot is already occupied, and the Submit button is disabled. Pick a different time or date. When two people submit for the same slot at the same moment, the system locks the slot for the first one only.',
 '• Free Slot: Green message confirms availability; Submit button active\n• Occupied Slot: Red conflict message appears; Submit button is disabled\n• Fix: Select another open hour on the calendar',
 'schedule', 'TUGON KB-61', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_61_slot_taken', 256)),

(324, 'KB-62 How to fix a schedule conflict',
 'choose another time,paano pumili ng ibang oras,calendar,conflict fix,paano ayusin ang schedule',
 'To fix a schedule conflict: Look at the list of existing bookings shown under the time field, and pick a time at least one hour after (or before) them. You can also check the parish calendar for open days. If you need a specific time that is taken, contact the parish office at 0997 742 8176. The bot cannot change or move other people\'s bookings.',
 '1. Check the existing bookings list displayed below the time field\n2. Select a slot at least 1 hour before or after existing bookings\n3. Consult the parish calendar for open dates\n4. For special coordination, contact the office at 0997 742 8176',
 'schedule', 'TUGON KB-62', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_62_fix_conflict', 256)),

(325, 'KB-63 Rescheduling and cancelling',
 'reschedule,change date,change time,palitan ang petsa,ilipat,cancel,kansela,i-cancel,move my schedule,pwede ba i-reschedule',
 'Rescheduling is NOT allowed after a request is submitted. Choose your date and time carefully before submitting: check the parish calendar and wait for the green "available" message. If a request was rejected (for example, due to a schedule conflict), follow the admin remarks and resubmit with a different date and time. For a cancellation or any urgent change, contact the parish office at 0997 742 8176.',
 '• Rescheduling Policy: Rescheduling is NOT allowed after submission\n• Please pick date & time carefully before submitting\n• If rejected: Read remarks and resubmit with a new time\n• For cancellations or urgent changes: Call parish office at 0997 742 8176',
 'schedule', 'TUGON KB-63', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_63_reschedule_cancel', 256)),

-- B8. Church registers
(326, 'KB-70 Registers kept by the parish',
 'register,libro,book,records,where is my record,hindi mahanap,libro ng binyag,libro ng kasal,parish records',
 'The parish keeps permanent books under custody of the Parish Priest: Baptismal Register (Libro ng Binyag), Confirmation Register (Libro ng Kumpil), Holy Communion Register (Libro ng Unang Komunyon), Matrimony Register (Libro ng Kasal), Death & Burial Register (Libro ng Libing). Certificates are printed in high-resolution PDF and issued only after entry is found and verified. If a record is not found, staff may reject with remarks asking for more details (correct spelling, year, parents\' names).',
 'Permanent Canonical Registry Books:\n• Baptismal Register (Libro ng Binyag)\n• Confirmation Register (Libro ng Kumpil)\n• Holy Communion Register (Libro ng Unang Komunyon)\n• Matrimony Register (Libro ng Kasal)\n• Death & Burial Register (Libro ng Libing)',
 'general', 'TUGON KB-70', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_70_registers', 256)),

-- B9. Notifications and security
(327, 'KB-80 Notifications',
 'sms,email,notification,abiso,text,abiso sa text,text message,email alert',
 'Notifications: You receive email and SMS when: your account is verified or rejected, your request moves to Processing, your request is Completed or Rejected, or your certificate is ready. SMS is sent to your registered Philippine mobile number (09XX XXX XXXX). Keep your mobile number and email up to date in your profile.',
 'Automated Notifications Sent For:\n• Account verification or rejection\n• Request moves to Processing\n• Request Completed or Rejected\n• Certificate ready for download / pickup',
 'system', 'TUGON KB-80', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_80_notifications', 256)),

(328, 'KB-81 OTP and account security',
 'otp,code,verification code,security,password,one-time pin,huwag ibigay ang otp,otp verification',
 'Sensitive account updates need a 6-digit One-Time PIN (OTP) sent to your verified mobile number. Never share your OTP or password with anyone, including in this chat. Parish staff and Tugon AI will never ask for it. If you do not receive the OTP, check that your mobile number is correct, ensure you have signal, wait a moment, and request again. If it still fails, contact the parish office.',
 '• Never share OTP or password in chat or with anyone\n• Enter OTP only on the secure portal verification screen\n• If OTP is not received: verify mobile number, check signal, or contact office at 0997 742 8176',
 'security', 'TUGON KB-81', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_81_otp_security', 256)),

-- B10. Schedules, contacts, and staff
(329, 'KB-90 Office hours',
 'office hours,open,oras ng opisina,bukas,sarado,closed,kailan bukas ang opisina,monday closed,office schedule',
 'Parish Office Hours: Tuesday to Saturday: 8:00 AM to 5:00 PM (lunch break 12:00 PM to 1:00 PM); Sunday: 7:00 AM to 12:00 PM (half-day morning only); Monday: CLOSED (clergy and staff rest day). Parish office transactions, document verification, and certificate releasing occur strictly during regular office hours.',
 '• Tuesday to Saturday: 8:00 AM – 5:00 PM (Lunch break: 12:00 PM – 1:00 PM)\n• Sunday: 7:00 AM – 12:00 PM (Half-day)\n• Monday: CLOSED (Rest day)',
 'office', 'TUGON KB-90', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_90_office_hours', 256)),

(330, 'KB-91 Mass schedule',
 'mass,misa,schedule,sunday mass,weekday mass,what time is mass,oras ng misa,iskedyul ng misa,kailan ang misa,sunday 9am mass',
 'Holy Mass Schedule: Sunday Masses: 6:00 AM (Bisaya), 8:00 AM (Tagalog), 10:00 AM (English), 4:00 PM (Tagalog), 5:30 PM (English), 7:00 PM (Youth / Tagalog). There is NO 9:00 AM Mass. Weekday Masses (Tuesday to Saturday): 6:30 AM (morning) and 6:00 PM (evening). Schedules may change for feast days and special solemnities; check announcements or call the office.',
 'Sunday Masses:\n• 6:00 AM (Bisaya)\n• 8:00 AM (Tagalog)\n• 10:00 AM (English)\n• 4:00 PM (Tagalog)\n• 5:30 PM (English)\n• 7:00 PM (Youth / Tagalog)\n*(Note: There is NO 9:00 AM Sunday Mass)*\n\nWeekday Masses (Tue-Sat):\n• 6:30 AM & 6:00 PM',
 'schedule', 'TUGON KB-91', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_91_mass_schedule', 256)),

(331, 'KB-92 Confession schedule',
 'confession,kumpisal,reconciliation,penance,oras ng kumpisal,kailan ang kumpisal,confession schedule,what time is confession',
 'Sacrament of Reconciliation (Confession) Schedule: Wednesday and Friday: 4:30 PM to 5:15 PM (before evening Mass); Saturday: 4:00 PM to 5:00 PM. Confessional location: Near the Sacred Heart Shrine. Also available by arrangement with a priest through the parish office (0997 742 8176) for sick calls and emergencies.',
 '• Wednesday & Friday: 4:30 PM – 5:15 PM (Before evening Mass)\n• Saturday: 4:00 PM – 5:00 PM\n• Location: Near the Sacred Heart Shrine\n• Sick calls / Urgent: By appointment via Parish Office (0997 742 8176)',
 'schedule', 'TUGON KB-92', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_92_confession_schedule', 256)),

(332, 'KB-93 Contacts and staff',
 'contact,phone,email,address,location,priest,secretary,saan,tawag,parish contact,where is the parish,telephone,hotline',
 'San Lorenzo Ruiz Parish Contacts and Staff: Parish Priest: Rev. Fr. Alberto G. Cahilig, OMI; Parochial Vicar: Rev. Fr. Alvin Vicente C. Barretto, OMI; Parish Secretary: Agnes C. Calapaan; Phone / Hotline: 0997 742 8176; Email: sanlorenzoruiz.midsayap@gmail.com; GCash: Agnes Calapaan, 0997 742 8176; Parish: San Lorenzo Ruiz Parish; Office Location: San Mateo, Aleosan, Cotabato; Archdiocese: Archdiocese of Cotabato; Portal: https://tugon-parish-system.vercel.app',
 '• Parish: San Lorenzo Ruiz Parish\n• Location: San Mateo, Aleosan, Cotabato\n• Archdiocese: Archdiocese of Cotabato\n• Parish Priest: Rev. Fr. Alberto G. Cahilig, OMI\n• Parochial Vicar: Rev. Fr. Alvin Vicente C. Barretto, OMI\n• Parish Secretary: Agnes C. Calapaan\n• Phone / Hotline / GCash: 0997 742 8176\n• Email: sanlorenzoruiz.midsayap@gmail.com\n• Portal: https://tugon-parish-system.vercel.app',
 'office', 'TUGON KB-93', 'active', 'approved', 1, '2026-09-30', 'bilingual', NOW(), SHA2('kb_93_contacts_staff', 256));
