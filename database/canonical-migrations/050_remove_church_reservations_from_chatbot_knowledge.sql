-- Canonical Migration 050: Remove Church Reservations from Chatbot Knowledge and User Workflows
-- Unifies parishioner tracking and services under My Requests (users/my-requests.php) and Request Service (users/request-service.php)

-- 1. Knowledge ID 122: Where to View Submitted Requests
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'You can view and track all your submitted certificate requests, sacramental services, and blessings in My Requests (users/my-requests.php).',
    `steps` = '1. Open My Requests (users/my-requests.php) to track certificates, sacramental services, and blessings\n2. Click on your Reference Number to review its live timeline, status, and remarks\n\n[View My Requests](../users/my-requests.php)',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('view_submitted_faq_v2', 256)
WHERE `knowledge_id` = 122;

-- 2. Knowledge ID 139: How Do I Know If Request Was Approved
UPDATE `chatbot_knowledge`
SET 
    `topic` = 'How Do I Know If My Request Was Approved',
    `keywords` = 'how do i know request approved,paano malalaman kung approved,request confirmation,check request approval,approval status',
    `answer` = 'You will receive an in-app notification, SMS alert, and Email confirmation once approved. You can also verify the status directly in My Requests (users/my-requests.php) where the badge turns green (Approved or Completed).',
    `steps` = '• Check in-app notifications 🔔\n• Check SMS text / Email message\n• View live status: [My Requests](../users/my-requests.php)',
    `category` = 'status',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('request_approved_faq_v2', 256)
WHERE `knowledge_id` = 139;

-- 3. Knowledge ID 113: How to Request a Sacramental Service
UPDATE `chatbot_knowledge`
SET 
    `topic` = 'How to Request a Sacramental Service',
    `keywords` = 'how to request service,request sacramental service,mag-request ng serbisyo,sacraments request,service schedule',
    `answer` = 'To request a sacramental service, open Request Service (users/request-service.php), choose the sacrament (Baptism, Wedding, Funeral Mass), pick an available schedule date and time slot from the calendar, upload required documents, and submit.',
    `steps` = '1. Open Request Service (users/request-service.php)\n2. Select the desired sacramental service\n3. Choose an open calendar timeslot\n4. Attach required documents and sponsor lists\n5. Submit for official review\n\n[Request Service](../users/request-service.php)',
    `category` = 'services',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('req_service_faq_v2', 256)
WHERE `knowledge_id` = 113;

-- 4. Knowledge ID 114: How to Request a Baptism Service
UPDATE `chatbot_knowledge`
SET 
    `steps` = '1. Open Request Service (users/request-service.php)\n2. Select "Baptism Service"\n3. Enter child and parent information\n4. Upload PSA Birth Certificate\n5. Select an available weekend timeslot\n6. Submit service request\n\n[Request Baptism](../users/request-service.php)',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('req_baptism_service_faq_v2', 256)
WHERE `knowledge_id` = 114;

-- 5. Knowledge ID 41: Sacramental Services and Blessings
UPDATE `chatbot_knowledge`
SET 
    `topic` = 'Sacramental Services and Blessings',
    `keywords` = 'sacramental services,blessings,parish services,service schedule,event request',
    `answer` = 'Sacramental service and blessing requests are scheduled and reviewed based on the service type, requested date, time, location, and parish liturgical calendar availability.',
    `steps` = '1. Choose Request Service or Request Blessing\n2. Select your preferred date, time, and location\n3. Provide the details and attach requirements\n4. Submit and track in My Requests (users/my-requests.php)\n\n[Request Service](../users/request-service.php)',
    `category` = 'services',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('sacraments_blessings_faq_v2', 256)
WHERE `knowledge_id` = 41;

-- 6. Knowledge ID 107: What Pending Status Means
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'Pending status means your request has been successfully received by the system and is currently in the queue awaiting review and validation by the parish office staff.',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('pending_status_faq_v2', 256)
WHERE `knowledge_id` = 107;

-- 7. Knowledge ID 108: What Approved Status Means
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'Approved status means your submitted details and supporting documents have been verified by the parish staff. For certificates, preparation begins; for sacramental services and blessings, your schedule is officially confirmed.',
    `steps` = '• Status: Approved / Confirmed\n• Certificates: Document is being prepared\n• Services & Blessings: Schedule and priest assignment confirmed\n\n[View My Requests](../users/my-requests.php)',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('approved_status_faq_v2', 256)
WHERE `knowledge_id` = 108;

-- 8. Knowledge ID 133: Available Parish Services
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'San Lorenzo Ruiz Parish offers online requests for:\n• Sacramental Services: Baptism, Confirmation, Holy Matrimony (Wedding), Funeral Mass, Mass Intentions\n• Parish Blessings: House, Vehicle, Business, Religious Articles\n• Official Certificates (Baptismal, Confirmation, First Communion, Marriage, Death).',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('available_services_faq_v2', 256)
WHERE `knowledge_id` = 133;

-- 9. Knowledge ID 141: Who Approves My Request
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'All parish requests are officially reviewed and approved by the Parish Secretary (Agnes C. Calapaan) and Parish Office Staff under the canonical authority of Parish Priest Rev. Fr. Alberto G. Cahilig, OMI.',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('who_approves_faq_v2', 256)
WHERE `knowledge_id` = 141;

-- 10. Knowledge ID 143: What Can TUGON AI Assistant Help With
UPDATE `chatbot_knowledge`
SET 
    `answer` = 'TUGON AI helps you with:\n1. Answering questions on certificate requirements and fees\n2. Providing Mass schedules, office hours, and event dates\n3. Checking the count and live status of your active requests\n4. Step-by-step guidance for requesting certificates, sacramental services, and blessings\n5. Explaining parish guidelines and terminology in English or Tagalog.',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('ai_capabilities_faq_v2', 256)
WHERE `knowledge_id` = 143;

-- 11. Knowledge ID 220: Multiple Certificate and Service Requests
UPDATE `chatbot_knowledge`
SET 
    `topic` = 'Multiple Certificate and Service Requests',
    `answer` = 'Yes! You can submit more than one certificate request, sacramental service, or blessing at the same time. Each submission receives its own unique Reference Number for independent tracking.',
    `reviewed_at` = NOW(),
    `content_hash` = SHA2('multiple_requests_faq_v2', 256)
WHERE `knowledge_id` = 220;
