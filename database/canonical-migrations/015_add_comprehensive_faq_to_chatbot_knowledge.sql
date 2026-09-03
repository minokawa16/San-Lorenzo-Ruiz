-- Canonical Migration 015: Add Comprehensive 48 FAQ Knowledge Entries to chatbot_knowledge
-- Covers Account, Certificates, Tracking, Statuses, Blessings, Sacraments, Schedules, Requirements, AI Capabilities, and Security.

REPLACE INTO chatbot_knowledge (knowledge_id, topic, keywords, answer, steps, category, source, status, approval_status, version, effective_date, language, reviewed_at, content_hash) VALUES
(101, 'How to Create a TUGON Account', 'create account,register,registration,sign up,mag-register,paano gumawa ng account,new user,register account', 
 'To create a TUGON account, visit the Registration page, provide your personal details (name, email, mobile number, address), set a secure password, upload a valid government ID for OCR identity verification, and complete the live face scan. Verify your email or phone OTP to activate your account.',
 '1. Open the Registration page (auth/register.php)\n2. Enter your complete personal and contact details\n3. Upload a clear copy of a valid Government ID\n4. Complete the live selfie face verification\n5. Enter the OTP sent to your phone/email to activate\n\n[Register Now](../auth/register.php)',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('create_account_faq', 256)),

(102, 'How to Log In to TUGON', 'login,log in,sign in,mag-login,pumasok sa account,access account,portal login',
 'To log in to your TUGON account, go to the Login page, enter your registered email address or mobile number and your password, then click Log In.',
 '1. Open the Login page (auth/login.php)\n2. Type your registered email or mobile number\n3. Enter your account password\n4. Click Log In (or complete OTP if enabled)\n\n[Log In](../auth/login.php)',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('login_faq', 256)),

(103, 'Request Baptismal Certificate', 'request baptismal certificate,baptism certificate,sertipiko ng binyag,kumuha ng baptismal certificate,baptism cert,pabinyag certificate',
 'To request a Baptismal Certificate, go to Certificate Requests, choose Baptismal Certificate, input the baptized person\'s name, date of birth, parents\' names, and purpose, upload your PSA Birth Certificate / Valid ID, and submit.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select Baptismal Certificate\n3. Fill in the required baptismal details and request purpose\n4. Upload a clear PSA Birth Certificate or Valid ID\n5. Click Submit and save your Reference Number\n\n[Request Baptism Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('request_baptism_cert_faq', 256)),

(104, 'Request Confirmation Certificate', 'request confirmation certificate,confirmation certificate,sertipiko ng kumpil,kumuha ng confirmation certificate,confirmation cert,kumpil certificate',
 'To request a Confirmation Certificate, navigate to Certificate Requests, select Confirmation Certificate, provide the confirmand\'s full name, approximate year of confirmation, and purpose, upload your identification, and submit.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select Confirmation Certificate\n3. Fill in the confirmand details and approximate year\n4. Upload your PSA Birth Certificate / Baptismal Certificate\n5. Submit your request for parish registry verification\n\n[Request Confirmation Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('request_confirmation_cert_faq', 256)),

(105, 'Request First Communion Certificate', 'request first communion certificate,communion certificate,sertipiko ng komunyon,first communion cert,komunyon certificate',
 'To request a First Communion Certificate, open Certificate Requests, select First Communion Certificate, enter the communicant\'s full name, year of communion, and school/chapel, upload your ID, and submit.',
 '1. Open Certificate Requests (users/request-certificate.php)\n2. Select First Communion Certificate\n3. Enter communicant details and year of first communion\n4. Upload your PSA Birth Certificate or Valid ID\n5. Click Submit to receive your Reference Number\n\n[Request Communion Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('request_communion_cert_faq', 256)),

(106, 'How to Track Certificate Request', 'track request,track certificate,check request status,kumusta ang request,follow up request,tingnan ang request,saan itatala',
 'You can track all your certificate requests in real time by navigating to My Requests in the user portal. You can view progress stages, admin notes, payment confirmation, and pickup schedules.',
 '1. Log in to your TUGON account\n2. Click My Requests from the sidebar menu (users/my-requests.php)\n3. Click on your request reference number to view detailed status and admin remarks\n\n[View My Requests](../users/my-requests.php)',
 'tracking', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('track_request_faq', 256)),

(107, 'What Pending Status Means', 'what does pending mean,pending status,ano ang pending,pending request,pending meaning',
 'Pending status means your request or reservation has been successfully received by the system and is currently in the queue awaiting review and validation by the parish office staff.',
 '• Status: Pending Review\n• Next Step: Parish staff checks submitted requirements and registry books\n• Action Required: None. You will be notified once reviewed.\n\n[View My Requests](../users/my-requests.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('status_pending_faq', 256)),

(108, 'What Approved Status Means', 'what does approved mean,approved status,ano ang approved,approved request,approved reservation,approved meaning',
 'Approved status means your submitted details and supporting documents have been verified by the parish staff. For certificates, preparation begins; for service reservations, your schedule is officially booked.',
 '• Status: Approved / Confirmed\n• Certificates: Document is being prepared and printed\n• Reservations: Schedule and priest assignment confirmed\n\n[View My Requests](../users/my-requests.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('status_approved_faq', 256)),

(109, 'What Processing Status Means', 'what does processing mean,processing status,ano ang processing,in processing,processing meaning',
 'Processing (or In Processing) status means your certificate is currently being formatted, printed on official parchment, authenticated against canonical registry books, and routed for signature and church dry sealing.',
 '• Status: In Processing\n• Document authentication and registry book entry verification\n• Affixing signature of Parish Priest and dry seal\n• You will receive a notification once Ready for Pickup\n\n[View My Requests](../users/my-requests.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('status_processing_faq', 256)),

(110, 'How to Submit a Blessing Request', 'submit blessing request,request blessing,magpa-bless,humingi ng basbas,blessing request,paano magpa-bless',
 'To submit a blessing request, go to Request Blessing, choose your blessing type (House, Vehicle, Business, Religious Item), specify your preferred date, time, and complete address, and submit for parish scheduling.',
 '1. Open Request Blessing (users/request-blessing.php)\n2. Select the blessing category\n3. Set your target date, time, and exact address/landmark\n4. Add contact information and special notes\n5. Submit for clergy assignment\n\n[Request Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('submit_blessing_faq', 256)),

(111, 'How to Request a House Blessing', 'request house blessing,house blessing,basbas ng bahay,pabasbas ng bahay,magpa-bless ng bahay',
 'To request a House Blessing, open Request Blessing, select House Blessing, provide your complete home address with nearby landmarks, preferred date and time, and submit for priest assignment.',
 '1. Go to Request Blessing (users/request-blessing.php)\n2. Select "House Blessing"\n3. Enter complete residential address and landmark\n4. Choose preferred date and time\n5. Submit request\n\n[Request House Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('house_blessing_faq', 256)),

(112, 'How to Request a Vehicle Blessing', 'request vehicle blessing,vehicle blessing,car blessing,motorcycle blessing,basbas ng sasakyan,pabasbas ng sasakyan',
 'To request a Vehicle Blessing, open Request Blessing, choose Vehicle Blessing, enter the vehicle type (Car, Motorcycle, Van), plate/conduction number, preferred schedule, and submit.',
 '1. Open Request Blessing (users/request-blessing.php)\n2. Select "Vehicle Blessing"\n3. Specify vehicle model, color, and plate number\n4. Select date and time\n5. Submit request\n\n[Request Vehicle Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('vehicle_blessing_faq', 256)),

(113, 'How to Make a Sacramental Service Reservation', 'sacramental reservation,make reservation,service reservation,magpa-reserve ng sakramento,book sacrament',
 'To make a sacramental service reservation, open Request Service or Make Reservation, pick the sacrament (Baptism, Wedding, Funeral Mass), choose an available date and time slot from the calendar, upload documents, and submit.',
 '1. Open Make Reservation (users/make-reservation.php) or Request Service (users/request-service.php)\n2. Select the desired sacramental service\n3. Choose an open calendar timeslot\n4. Attach required documents and sponsor lists\n5. Submit for official review\n\n[Make Reservation](../users/make-reservation.php)',
 'reservations', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('sacramental_res_faq', 256)),

(114, 'How to Request a Baptism Service', 'request baptism service,baptism reservation,magpa-binyag,schedule binyag,pabinyag service,binyag schedule',
 'To request a Baptism service, go to Request Service, choose Baptism, provide the child\'s details, parents\' names, godparent list, upload the PSA Birth Certificate, select a Saturday or Sunday schedule, and submit.',
 '1. Open Request Service (users/request-service.php)\n2. Select "Baptism Service"\n3. Enter child and parent information\n4. Upload PSA Birth Certificate\n5. Select an available weekend timeslot\n6. Submit reservation\n\n[Reserve Baptism](../users/request-service.php)',
 'reservations', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('baptism_service_faq', 256)),

(115, 'How to Request a Marriage or Wedding Service', 'request marriage service,wedding reservation,magpakasal,kasal schedule,schedule kasal,wedding booking',
 'To request a Wedding service, open Request Service, select Matrimony/Wedding, enter bride and groom details, select your target date (at least 1 to 3 months in advance), and submit for canonical interview scheduling.',
 '1. Open Request Service (users/request-service.php)\n2. Select "Matrimony / Wedding"\n3. Provide bride and groom personal records\n4. Select proposed wedding date (recommended: 2-3 months ahead)\n5. Submit for canonical interview and banns scheduling\n\n[Reserve Wedding](../users/request-service.php)',
 'reservations', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('wedding_service_faq', 256)),

(116, 'How to Request a Funeral Mass', 'request funeral mass,funeral blessing,misa sa patay,libing schedule,funeral service reservation',
 'To request a Funeral Mass or blessing, go to Request Service, select Funeral Mass, provide the deceased person\'s name, date of passing, target mass schedule, upload the official Death Certificate, and submit.',
 '1. Open Request Service (users/request-service.php)\n2. Select "Funeral Mass / Blessing"\n3. Enter the deceased\'s name and details\n4. Upload the official Death Certificate\n5. Select preferred mass date and time\n6. Submit for immediate clergy assignment\n\n[Reserve Funeral Mass](../users/request-service.php)',
 'reservations', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('funeral_mass_faq', 256)),

(117, 'View Available Parish Schedules', 'view schedules,parish schedule,available schedule,calendar schedule,tingnan ang iskedyul,oras ng misa',
 'You can view the full parish calendar and available service schedules on the View Schedule page in your parishioner dashboard.',
 '1. Click View Schedule from the navigation menu (users/view-schedule.php)\n2. Browse monthly and weekly calendar events\n3. Check available slots for masses, confessions, and blessings\n\n[View Schedule](../users/view-schedule.php)',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('view_schedules_faq', 256)),

(118, 'Upcoming Mass Schedules', 'upcoming mass schedules,mass times,mass schedule,oras ng misa,linggo misa,daily mass,sunday mass schedule',
 'Regular Sunday Masses are celebrated at 6:00 AM, 8:00 AM, 10:00 AM, 4:00 PM, 5:30 PM, and 7:00 PM. Weekday Masses (Tuesday to Saturday) are held at 6:30 AM and 6:00 PM. Check the online calendar for special feast day schedules.',
 '• Sunday Masses: 6:00 AM, 8:00 AM, 10:00 AM, 4:00 PM, 5:30 PM, 7:00 PM\n• Weekday Masses: 6:30 AM, 6:00 PM\n• View live updates: [Parish Calendar](../users/view-schedule.php)',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('mass_schedule_faq', 256)),

(119, 'Where to View Parish Announcements', 'view announcements,parish announcements,mga anunsyo,balita sa parokya,church news,announcements page',
 'You can read official parish announcements, liturgical advisories, feast day reminders, and community events on the Announcements page.',
 '1. Open Announcements from the sidebar (users/announcements.php)\n2. Filter by category or date\n3. Read detailed articles, attachments, and schedules\n\n[View Announcements](../users/announcements.php)',
 'announcements', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('view_announcements_faq', 256)),

(120, 'How to Upload Valid ID', 'upload valid id,valid id,paano mag-upload ng id,upload id,id submission,id requirements',
 'During registration or service submission, click the upload box or drag-and-drop a clear image (JPG, PNG) or PDF of your government-issued ID (e.g. PhilSys, Driver\'s License, Passport, UMID, Postal ID, Voter\'s ID). Ensure text is sharp and uncropped.',
 '1. Prepare your valid Government ID (PhilSys, Passport, Driver\'s License, UMID, Postal ID)\n2. Ensure all text and corners are visible and well-lit\n3. Click "Upload Valid ID" and select the file (max 10MB)\n4. The system will perform automated OCR verification\n\n[Profile Settings](../auth/profile.php)',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('upload_id_faq', 256)),

(121, 'What Documents Do I Need to Submit', 'what documents to submit,documents needed,required documents,mga dokumento,requirements list',
 'Requirements depend on your request:\n• Baptism Certificate: PSA Birth Certificate, Valid ID\n• Confirmation Certificate: Baptismal Certificate, PSA Birth Certificate\n• Marriage Certificate: PSA Marriage Contract / Date of Wedding\n• Baptism Service: PSA Birth Certificate, Parents\' Marriage Contract, Sponsor list\n• Wedding Service: PSA Birth Certificates, CENOMAR, Annotated Baptismal/Confirmation certs, Pre-Cana cert, Marriage License.',
 'Check requirement details on each form:\n[Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('documents_needed_faq', 256)),

(122, 'Where to View Submitted Requests', 'where to view submitted requests,my requests,tingnan ang mga request,submitted requests list,view requests',
 'You can view all your submitted certificate requests and blessings in My Requests (users/my-requests.php). For facility and sacramental reservations, open My Reservations (users/my-reservations.php).',
 '1. Open My Requests (users/my-requests.php) for certificates and blessings\n2. Open My Reservations (users/my-reservations.php) for church bookings\n\n[View My Requests](../users/my-requests.php)',
 'tracking', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('view_submitted_faq', 256)),

(123, 'How to Check Details of My Request', 'check request details,request details,view request info,detalye ng request,request status details',
 'To check details, go to My Requests (users/my-requests.php) and click on your request card or "View Details". You will see submitted information, uploaded documents, status timeline, and parish staff remarks.',
 '1. Go to My Requests (users/my-requests.php)\n2. Click on your request Reference Number\n3. Review the complete timeline, payment card, and remarks\n\n[View My Requests](../users/my-requests.php)',
 'tracking', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('check_details_faq', 256)),

(124, 'How to Update Profile Information', 'update profile,edit profile,change profile information,palitan ang profile,update contact number,change address',
 'To update your profile information, click your name/avatar or select Profile Settings (auth/profile.php) from the menu. You can edit your phone number, home address, and personal information, then save.',
 '1. Open Profile Settings (auth/profile.php)\n2. Edit your contact number, address, or profile picture\n3. Click Save Changes\n\n[Profile Settings](../auth/profile.php)',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('update_profile_faq', 256)),

(125, 'How to Change Password', 'change password,palitan ang password,update password,reset password,forgot password,new password',
 'To change your password, go to Profile Settings (auth/profile.php), scroll to Change Password, enter your current password and your new password (minimum 8 characters), and submit. If forgotten, use Forgot Password on the login page.',
 '1. Go to Profile Settings (auth/profile.php)\n2. Enter Current Password\n3. Enter and confirm New Password\n4. Click Update Password\n\n[Profile Settings](../auth/profile.php)',
 'account', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('change_password_faq', 256)),

(126, 'Manage Notification Preferences', 'notification preferences,manage notifications,sms notifications,email notifications,settings notifications',
 'You can manage your notification preferences in Profile Settings or the Notifications page (users/notifications.php). Choose whether to receive SMS, Email, and In-App alerts for request milestones.',
 '1. Open Notifications (users/notifications.php) or Profile Settings (auth/profile.php)\n2. Toggle SMS, Email, or In-App alerts\n3. Save preferences\n\n[Notification Center](../users/notifications.php)',
 'notifications', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('manage_notifications_faq', 256)),

(127, 'Where to View Notifications', 'where to view notifications,notifications center,mga abiso,tingnan ang notifications,bell icon',
 'Click the bell icon in the top header or select Notifications (users/notifications.php) from your sidebar to see all real-time transaction updates, status changes, and announcements.',
 '1. Click the 🔔 Bell icon in the top bar or menu\n2. Open Notifications (users/notifications.php)\n3. Click any alert to view its associated request or announcement\n\n[View Notifications](../users/notifications.php)',
 'notifications', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('view_notifications_faq', 256)),

(128, 'Why Was My Request Rejected', 'why was my request rejected,bakit na-reject,dahilan ng rejection,rejection reason,rejected request',
 'Requests are typically rejected due to: 1) Blurry/unclear document uploads, 2) Name or date discrepancies compared to canonical registry records, 3) Missing mandatory requirements, or 4) Booking schedule conflicts. The specific reason is written in your request remarks.',
 '1. Open My Requests (users/my-requests.php)\n2. Click on the Rejected request\n3. Read the Admin Remarks to see the exact missing item or explanation\n\n[View My Requests](../users/my-requests.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('why_rejected_faq', 256)),

(129, 'What to Do If Request Was Rejected', 'what to do if rejected,rejected request next steps,ano ang gagawin kapag na-reject,paano ayusin ang rejected',
 'If your request was rejected, read the staff remarks on your request page, prepare the correct document or information, and submit a new request. If you need clarification, contact the parish office.',
 '1. Read the rejection remarks in My Requests (users/my-requests.php)\n2. Secure the corrected document (e.g. clear PSA copy)\n3. Submit a new request with the updated file\n\n[Request Certificate](../users/request-certificate.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('what_to_do_rejected_faq', 256)),

(130, 'Can I Submit Another Request After Rejection', 'submit another request after rejection,puwede bang mag-request ulit,re-submit request,magsumite muli',
 'Yes, you can submit a new request immediately after addressing the notes specified by the parish office. There is no waiting penalty for re-submitting with complete documents.',
 '• You may submit a new request anytime\n• Ensure all previously noted corrections are resolved\n\n[Request Certificate](../users/request-certificate.php) • [Request Service](../users/request-service.php)',
 'status', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('submit_after_rejection_faq', 256)),

(131, 'Certificate Processing Time', 'processing time,gaano katagal ang certificate,how long does certificate take,kailan makukuha ang certificate,release date',
 'Certificate processing typically takes 2 to 3 working days from document verification and payment confirmation. You will receive an SMS and email notification when your certificate is Ready for Pickup.',
 '• Standard Processing: 2 to 3 working days\n• Claiming: Bring 1 valid ID and your Reference Number to the Parish Office\n\n[Check My Requests](../users/my-requests.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('processing_time_faq', 256)),

(132, 'Available Certificate Types in TUGON', 'available certificates,certificate types,mga sertipiko,uri ng sertipiko,list of certificates',
 'TUGON provides official issuance for:\n1. Baptismal Certificate\n2. Confirmation Certificate\n3. First Holy Communion Certificate\n4. Marriage Certificate\n5. Death / Funeral Certificate & Certificate of Good Moral Character.',
 'Select your certificate type: [Request Certificate](../users/request-certificate.php)',
 'certificates', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('available_certs_faq', 256)),

(133, 'Available Parish Services', 'available services,parish services list,mga serbisyo ng parokya,serbisyo sa simbahan,services offered',
 'San Lorenzo Ruiz Parish offers online requests for:\n• Sacramental Services: Baptism, Confirmation, Holy Matrimony (Wedding), Funeral Mass, Mass Intentions\n• Parish Blessings: House, Vehicle, Business, Religious Articles\n• Venue Bookings: Parish Hall and Church facilities\n• Official Certificates.',
 'Explore services: [Request Service](../users/request-service.php) • [Request Blessing](../users/request-blessing.php)',
 'services', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('available_services_faq', 256)),

(134, 'Requirements for Baptism', 'baptism requirements,binyag requirements,mga kailangan sa binyag,baptism service requirements',
 'Requirements for Baptism:\n1. PSA / Local Civil Registrar Birth Certificate of the child\n2. Parents\' Catholic Marriage Certificate (if married)\n3. List of Sponsors (at least 1 Catholic godparent)\n4. Pre-Baptismal Seminar attendance\n5. Parish Permission Letter (if living outside parish territory).',
 'Prepare these files and book your schedule: [Reserve Baptism](../users/request-service.php)',
 'sacraments', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('req_baptism_faq', 256)),

(135, 'Requirements for Confirmation', 'confirmation requirements,kumpil requirements,mga kailangan sa kumpil,kumpil documents',
 'Requirements for Confirmation:\n1. PSA Birth Certificate\n2. Baptismal Certificate with notation "For Confirmation Purposes"\n3. One Catholic sponsor (Ninong/Ninang)\n4. Attendance in Confirmation Catechesis and preparation class.',
 'Submit request: [Request Service](../users/request-service.php)',
 'sacraments', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('req_confirmation_faq', 256)),

(136, 'Requirements for Marriage', 'marriage requirements,wedding requirements,mga kailangan sa kasal,kasal documents,wedding checklist',
 'Requirements for Holy Matrimony / Wedding:\n1. PSA Birth Certificate (Bride & Groom)\n2. PSA CENOMAR (Certificate of No Marriage)\n3. Annotated Baptismal & Confirmation Certificates ("For Marriage Purposes", valid within 6 months)\n4. Pre-Cana Marriage Preparation Seminar Certificate\n5. Canonical Interview with Parish Priest\n6. Publication of Marriage Banns (3 Sundays)\n7. Marriage License or Article 34 Affidavit.',
 'Book your wedding: [Reserve Wedding](../users/request-service.php)',
 'sacraments', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('req_marriage_faq', 256)),

(137, 'Requirements for First Communion', 'first communion requirements,komunyon requirements,mga kailangan sa komunyon',
 'Requirements for First Holy Communion:\n1. PSA Birth Certificate\n2. Baptismal Certificate\n3. Completion of First Communion Catechism instruction\n4. First Confession (Sacrament of Reconciliation).',
 'Contact the parish catechetical ministry or submit online: [Request Service](../users/request-service.php)',
 'sacraments', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('req_communion_faq', 256)),

(138, 'Information Needed for Blessing Request', 'information for blessing,blessing requirements,mga kailangan sa blessing,anong kailangan sa blessing',
 'When requesting a blessing, please prepare:\n1. Category of blessing (House, Vehicle, Business, Religious Items)\n2. Complete venue address and nearby landmarks\n3. Preferred date and time\n4. Contact person name and active mobile number\n5. Any special intentions or notes for the priest.',
 'Submit online: [Request Blessing](../users/request-blessing.php)',
 'blessings', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('info_blessing_faq', 256)),

(139, 'How Do I Know If Reservation Was Approved', 'how do i know reservation approved,paano malalaman kung approved,reservation confirmation,check reservation approval',
 'You will receive an in-app notification, SMS alert, and Email confirmation once approved. You can also verify the status directly in My Reservations (users/my-reservations.php) where the badge turns green (Approved).',
 '• Check in-app notifications 🔔\n• Check SMS text / Email message\n• View live status: [My Reservations](../users/my-reservations.php)',
 'reservations', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('reservation_approved_faq', 256)),

(140, 'Can I Change My Requested Schedule', 'change schedule,reschedule,palitan ang schedule,baguhin ang petsa,change date reservation',
 'If your request is still Pending, you can cancel and submit with your new schedule or contact the parish office. If already Approved, please reach out directly to the parish office so staff can adjust the calendar without conflicting with other bookings.',
 'Contact Parish Secretary at 0997 742 8176 for schedule adjustments.',
 'schedule', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('change_schedule_faq', 256)),

(141, 'Who Approves My Request', 'who approves request,sino ang nag-aapruba,approval authority,parish approval',
 'All requests and reservations are officially reviewed and approved by the Parish Secretary (Agnes C. Calapaan) and Parish Office Staff under the canonical authority of Parish Priest Rev. Fr. Alberto G. Cahilig, OMI.',
 '• Reviews: Parish Office Staff & Secretary (Agnes C. Calapaan)\n• Pastoral Approval: Parish Priest (Rev. Fr. Alberto G. Cahilig, OMI)',
 'office', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('who_approves_faq', 256)),

(142, 'Can AI Assistant Approve My Request', 'can ai approve request,puwede bang i-approve ng ai,ai approval,ai authority',
 'No. TUGON AI is a read-only assistant designed to provide information, smart form guidance, and status tracking. AI cannot approve, modify, or issue official records. Only human parish staff can approve transactions.',
 '• TUGON AI: Read-only information & navigation guidance\n• Approvals: Exclusively handled by authorized parish staff',
 'ai', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('can_ai_approve_faq', 256)),

(143, 'What Can TUGON AI Assistant Help With', 'what can ai help with,ano ang maitutulong ng ai,ai assistant features,ai capabilities',
 'TUGON AI helps you with:\n1. Answering questions on certificate requirements and fees\n2. Providing Mass schedules, office hours, and event dates\n3. Checking the count and live status of your active requests\n4. Step-by-step guidance for booking blessings and reservations\n5. Explaining parish guidelines and terminology in English or Tagalog.',
 'Ask me anything about parish services or request status!',
 'ai', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('what_ai_helps_faq', 256)),

(144, 'How Does TUGON Protect My Information', 'how is data protected,privacy,security,data privacy,seguridad ng impormasyon,data protection',
 'TUGON protects your information through: 1) Strong password encryption (bcrypt), 2) SSL/TLS encrypted connections, 3) Automated redaction of sensitive identifiers in AI query logs, 4) Strict Role-Based Access Control, and 5) Encrypted regular backups.',
 '• Compliance with Data Privacy standards\n• Secure credential hashing & session protection\n• Redacted AI audit trails',
 'security', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('protect_info_faq', 256)),

(145, 'Who Can See My Submitted Documents', 'who can see my documents,sino ang makakakita ng dokumento,document privacy,who has access to id',
 'Only you (the account owner) and authorized Parish Personnel (Parish Priest & Secretary) have permission to view your uploaded IDs and certificates. Files are protected from public access.',
 '• Private authenticated storage\n• Restricted to authorized parish records staff only',
 'security', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('who_sees_docs_faq', 256)),

(146, 'What If I Uploaded Wrong Document', 'uploaded wrong document,maling dokumento,palitan ang uploaded file,wrong file uploaded',
 'If your request is still Pending, you may contact the parish office with your Reference Number or submit the corrected file. If staff discovers the issue during review, they will add an admin note requesting the correct file.',
 'Contact Parish Secretary at 0997 742 8176 or check remarks in [My Requests](../users/my-requests.php).',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('wrong_doc_faq', 256)),

(147, 'What If My Information Is Incorrect', 'information is incorrect,maling impormasyon,wrong details,correct information,update request info',
 'For profile details, update them in Profile Settings (auth/profile.php). If you notice an error in a submitted request, contact the parish office with your Reference Number so staff can update your record before printing.',
 '1. Profile details: Update in [Profile Settings](../auth/profile.php)\n2. Active requests: Contact parish office with your Reference Number',
 'documents', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('incorrect_info_faq', 256)),

(148, 'How to Contact the Parish', 'contact parish,contact parish office,telepono ng parokya,parish office hours,contact secretary,lokasyon ng parokya,address ng parokya',
 'You can contact San Lorenzo Ruiz Parish through:\n• Parish Secretary (Agnes C. Calapaan): 0997 742 8176\n• Parish Priest: Rev. Fr. Alberto G. Cahilig, OMI\n• Office Hours: Tuesday – Saturday: 8:00 AM – 5:00 PM (Lunch: 12:00 PM – 1:00 PM) | Sunday: 7:00 AM – 12:00 PM | Monday: Closed.',
 '📞 Phone: 0997 742 8176\n🕒 Tuesday - Saturday: 8:00 AM - 5:00 PM | Sunday: 7:00 AM - 12:00 PM | Monday: Closed',
 'office', 'TUGON parish knowledge base', 'active', 'approved', 1, '2026-09-03', 'bilingual', NOW(), SHA2('contact_parish_faq', 256));

REPLACE INTO chatbot_knowledge_meta (meta_key, meta_value)
VALUES ('official_dataset_version', '2026-09-03-comprehensive-48faq-v1');
