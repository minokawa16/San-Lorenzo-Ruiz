<?php
/**
 * Intelligent Conversational Intent Layer for TUGON AI.
 *
 * Provides natural conversational understanding, timezone-aware greetings (Asia/Manila),
 * bilingual Tagalog/Taglish/English matching, small talk handling, identity/capability explanations,
 * and conversational routing.
 */
final class TugonConversationalIntent
{
    const GREETING = 'GREETING';
    const GOOD_MORNING = 'GOOD_MORNING';
    const GOOD_AFTERNOON = 'GOOD_AFTERNOON';
    const GOOD_EVENING = 'GOOD_EVENING';
    const GOOD_NIGHT = 'GOOD_NIGHT';
    const HOW_ARE_YOU = 'HOW_ARE_YOU';
    const THANKS = 'THANKS';
    const FAREWELL = 'FAREWELL';
    const OKAY = 'OKAY';
    const NICE = 'NICE';
    const WHO_ARE_YOU = 'WHO_ARE_YOU';
    const WHAT_CAN_YOU_DO = 'WHAT_CAN_YOU_DO';
    const HELP = 'HELP';

    /**
     * Analyze a message for conversational intent.
     *
     * @param string $message
     * @param DateTimeInterface|null $now
     * @return array
     */
    public static function analyze($message, DateTimeInterface $now = null)
    {
        $normalized = self::normalize($message);
        $language = self::detectLanguage($message, $normalized);
        $period = self::currentPeriod($now);
        $result = [
            'intent' => null,
            'normalized' => $normalized,
            'language' => $language,
            'period' => $period,
            'is_pure_social' => false,
            'greeting_detected' => false,
            'greeting_acknowledgement' => null,
            'response' => null,
            'suggested_prompts' => []
        ];

        if ($normalized === '') {
            return $result;
        }

        // 1. Who are you / Identity
        if (preg_match('/^(?:who are you|sino ka(?: po)?|sino po kayo|what is your name|ano(?:ng)? pangalan mo|who made you|sino gumawa sa yo)(?: po)?$/u', $normalized)) {
            return self::withResponse($result, self::WHO_ARE_YOU, self::whoAreYouResponses($language), true);
        }

        // 2. What can you do / Capabilities / Help
        if (preg_match('/^(?:what can you do|what are your capabilities|ano(?:ng)? kaya mong gawin|ano(?:ng)? maitutulong mo|how can you help(?: me)?|help(?: me)?|tulong(?: po)?|ano po ang pwede mong gawin)(?: po)?$/u', $normalized)) {
            $prompts = ['Baptism Requirements', 'Certificate Request', 'Mass Schedule', 'Reservations', 'Parish Office Hours'];
            return self::withResponse($result, self::WHAT_CAN_YOU_DO, self::capabilitiesResponses($language), true, $prompts);
        }

        // 3. How are you / Status
        if (preg_match('/^(?:how are you(?: doing)?|how s it going|how are u|kamusta ka(?: po)?|kumusta ka(?: po)?|musta ka(?: po)?|musta na(?: po)?)(?: tugon(?: ai)?)?$/u', $normalized)) {
            return self::withResponse($result, self::HOW_ARE_YOU, self::howAreYouResponses($language), true);
        }

        // 4. Good night
        if (preg_match('/^(?:good night|goodnight|gud night|gud nyt|tulog na po|pahinga na po|matulog na ko)(?: po)?(?: tugon(?: ai)?)?$/u', $normalized)) {
            return self::withResponse($result, self::GOOD_NIGHT, self::goodNightResponses($language), true);
        }

        // 5. Thanks / Gratitude
        if (preg_match('/^(?:thank you(?: so much| very much)?|thanks(?: a lot)?|thx|ty|salamat(?: po)?|maraming salamat(?: po)?|salamat nang marami)(?: po)?(?: tugon(?: ai)?)?$/u', $normalized)) {
            return self::withResponse($result, self::THANKS, self::thanksResponses($language), true);
        }

        // 6. Okay / Acknowledgement
        if (preg_match('/^(?:ok|okay|alright|noted|cge|sige|sige po|okie|oki|understood)(?: po)?$/u', $normalized)) {
            return self::withResponse($result, self::OKAY, self::okayResponses($language), true);
        }

        // 7. Nice / Praise
        if (preg_match('/^(?:nice|great|awesome|cool|galing|ang galing|ayos|good job|very good|superb)(?: po)?$/u', $normalized)) {
            return self::withResponse($result, self::NICE, self::niceResponses($language), true);
        }

        // 8. Farewell / Goodbye
        if (preg_match('/^(?:bye|goodbye|see you(?: later)?|paalam|aalis na po ako|babye|bye bye)(?: po)?$/u', $normalized)) {
            return self::withResponse($result, self::FAREWELL, self::farewellResponses($language), true);
        }

        // 9. Pure Time-Specific or General Greetings
        $greetingMatch = self::extractLeadingGreeting($normalized);
        if ($greetingMatch !== null) {
            $result['greeting_detected'] = true;
            $result['greeting_acknowledgement'] = self::greetingAcknowledgement($language, $period);

            // If the user's message is ONLY a greeting without a follow-up question
            if ($greetingMatch['remainder'] === '') {
                $specificIntent = $greetingMatch['specific_intent'] ?: self::GREETING;
                $prompts = ['Baptism Requirements', 'Certificate Request', 'Mass Schedule', 'Reservations'];
                return self::withResponse($result, $specificIntent, self::greetingResponses($language, $period, $specificIntent), true, $prompts);
            }
        }

        return $result;
    }

    /**
     * Normalize conversational input while handling informal spellings and typos.
     */
    public static function normalize($message)
    {
        $text = mb_strtolower(trim((string) $message), 'UTF-8');
        $text = str_replace(['’', "'", '`'], ' ', $text);

        // Normalize common casual spellings
        $text = preg_replace('/\bhel+o+\b/u', 'hello', $text);
        $text = preg_replace('/\bh+i+\b/u', 'hi', $text);
        $text = preg_replace('/\bhe+y+\b/u', 'hey', $text);
        $text = preg_replace('/\bg+u+d\b/u', 'good', $text);
        $text = preg_replace('/\bgood\s*morning\b/u', 'good morning', $text);
        $text = preg_replace('/\bgood\s*afternoon\b/u', 'good afternoon', $text);
        $text = preg_replace('/\bgood\s*evening\b/u', 'good evening', $text);
        $text = preg_replace('/\bgood\s*night\b/u', 'good night', $text);
        $text = preg_replace('/\bgud\s*am\b/u', 'good morning', $text);
        $text = preg_replace('/\bgud\s*pm\b/u', 'good afternoon', $text);
        $text = preg_replace('/\bkamusta\b/u', 'kumusta', $text);
        $text = preg_replace('/\bmusta\b/u', 'kumusta', $text);
        $text = preg_replace('/\bmagandang\s*umaga\b/u', 'magandang umaga', $text);
        $text = preg_replace('/\bmagandang\s*hapon\b/u', 'magandang hapon', $text);
        $text = preg_replace('/\bmagandang\s*gabi\b/u', 'magandang gabi', $text);

        // Clean punctuation
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Determine current time period in Asia/Manila.
     *
     * 05:00 - 11:59: morning
     * 12:00 - 17:59: afternoon
     * 18:00 - 21:59: evening
     * 22:00 - 04:59: night
     */
    public static function currentPeriod(DateTimeInterface $now = null): string
    {
        if ($now === null) {
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            } catch (Throwable $e) {
                $now = new DateTimeImmutable('now');
            }
        }

        $hour = (int) $now->format('G');
        if ($hour >= 5 && $hour < 12) {
            return 'morning';
        }
        if ($hour >= 12 && $hour < 18) {
            return 'afternoon';
        }
        if ($hour >= 18 && $hour < 22) {
            return 'evening';
        }
        return 'night';
    }

    /**
     * Detect language: 'en', 'fil', or 'taglish'.
     */
    public static function detectLanguage(string $rawMessage, string $normalized): string
    {
        $filipinoKeywords = [
            'po', 'opo', 'ano', 'ano-ano', 'anong', 'paano', 'saan', 'kailan', 'magkano', 'bakit',
            'kailangan', 'binyag', 'kumpil', 'komunyon', 'kasal', 'basbas', 'sertipiko', 'misa',
            'opisina', 'pari', 'parokya', 'kahilingan', 'salamat', 'maraming', 'kamusta', 'kumusta',
            'musta', 'magandang', 'umaga', 'hapon', 'gabi', 'sige', 'puwede', 'pwede', 'nais', 'gusto',
            'ninyo', 'namin', 'inyo', 'kayo', 'ako', 'ko', 'mo', 'ba', 'naman', 'nga', 'din', 'rin', 'ito', 'iyan'
        ];

        $englishKeywords = [
            'what', 'how', 'when', 'where', 'why', 'who', 'which', 'is', 'are', 'can', 'could',
            'would', 'need', 'requirements', 'process', 'certificate', 'baptism', 'wedding',
            'marriage', 'confirmation', 'communion', 'mass', 'schedule', 'reservation', 'contact',
            'office', 'hours', 'priest', 'good', 'morning', 'afternoon', 'evening', 'night', 'hello', 'hi', 'thanks', 'thank'
        ];

        $tokens = preg_split('/\s+/u', mb_strtolower($normalized));
        $filCount = 0;
        $enCount = 0;

        foreach ($tokens as $token) {
            if (in_array($token, $filipinoKeywords, true)) {
                $filCount++;
            }
            if (in_array($token, $englishKeywords, true)) {
                $enCount++;
            }
        }

        if ($filCount > 0 && $enCount > 0) {
            return 'taglish';
        }
        if ($filCount > 0) {
            return 'fil';
        }
        return 'en';
    }

    private static function extractLeadingGreeting($normalized)
    {
        $patterns = [
            self::GOOD_MORNING => '/^(?:good morning|goodmorning|magandang umaga|gud am)(?: po)?(?: tugon(?: ai)?)?\b/',
            self::GOOD_AFTERNOON => '/^(?:good afternoon|goodafternoon|magandang hapon|gud pm)(?: po)?(?: tugon(?: ai)?)?\b/',
            self::GOOD_EVENING => '/^(?:good evening|goodevening|magandang gabi)(?: po)?(?: tugon(?: ai)?)?\b/',
            self::GREETING => '/^(?:hello|hi|hey|heyy|kumusta|kamusta|musta|greetings)(?: there)?(?: po)?(?: tugon(?: ai)?)?\b/'
        ];

        foreach ($patterns as $specificIntent => $pattern) {
            if (preg_match($pattern, $normalized, $match)) {
                $remainder = trim(substr($normalized, strlen($match[0])));
                $remainder = preg_replace('/^(?:and|at|pero|but|so|ask ko lang po|tanong ko lang po)\s+/u', '', $remainder);
                return [
                    'greeting' => $match[0],
                    'specific_intent' => $specificIntent,
                    'remainder' => trim((string) $remainder)
                ];
            }
        }

        return null;
    }

    private static function greetingAcknowledgement($language, $period)
    {
        if ($language === 'fil' || $language === 'taglish') {
            $map = [
                'morning' => 'Magandang umaga po! ☀️',
                'afternoon' => 'Magandang hapon po! 😊',
                'evening' => 'Magandang gabi po! 🌙',
                'night' => 'Magandang gabi po! 🌙'
            ];
            return $map[$period] ?? 'Magandang araw po! 😊';
        }

        $map = [
            'morning' => 'Good morning! ☀️',
            'afternoon' => 'Good afternoon! 😊',
            'evening' => 'Good evening! 🌙',
            'night' => 'Good evening! 🌙'
        ];
        return $map[$period] ?? 'Hello! 👋';
    }

    private static function greetingResponses($language, $period, $specificIntent = self::GREETING)
    {
        if ($language === 'fil' || $language === 'taglish') {
            if ($specificIntent === self::GOOD_MORNING || ($specificIntent === self::GREETING && $period === 'morning')) {
                return [
                    "Magandang umaga po at sumainyo ang kapayapaan! ☀️ Ako po ang inyong TUGON Parish Guide. Handa po akong tumulong sa mga serbisyo ng parokya, iskedyul ng misa, sakramento, at mga kahilingan.",
                    "Magandang umaga po! Pagpalain nawa ang inyong araw. Paano po kayo matutulungan ng TUGON Parish Guide sa inyong mga katanungan sa simbahan?"
                ];
            }
            if ($specificIntent === self::GOOD_AFTERNOON || ($specificIntent === self::GREETING && $period === 'afternoon')) {
                return [
                    "Magandang hapon po at sumainyo ang kapayapaan! 🕊️ Ang TUGON Parish Guide ay narito upang tumulong sa inyong mga sacramental records, schedules, at mga certificate requests.",
                    "Magandang hapon po! Pagpalain po ang inyong araw. Paano po kayo matutulungan ngayon ng TUGON Parish Guide?"
                ];
            }
            if ($specificIntent === self::GOOD_EVENING || ($specificIntent === self::GREETING && ($period === 'evening' || $period === 'night'))) {
                return [
                    "Magandang gabi po at sumainyo ang kapayapaan! 🌙 Narito po ang TUGON Parish Guide upang tumulong sa inyong mga katanungan tungkol sa serbisyo ng parokya at mga iskedyul.",
                    "Magandang gabi po! Nawa'y maging mapayapa ang inyong gabi. Ano po ang maitutulong ko sa inyo para sa mga serbisyo ng ating simbahan?"
                ];
            }
            return [
                "Magandang araw po at sumainyo ang kapayapaan! 👋 Ako po ang inyong TUGON Parish Guide. Paano po kita matutulungan sa mga sakramento, sertipiko, o iskedyul ng misa ngayon?",
                "Kumusta po! Pagpalain po kayo. Narito ang TUGON Parish Guide upang magbigay-gabay sa inyong mga kahilingan sa parokya."
            ];
        }

        // English greetings using dynamic time
        if ($specificIntent === self::GOOD_MORNING || ($specificIntent === self::GREETING && $period === 'morning')) {
            return [
                "Good morning! Peace be with you. ☀️ TUGON Parish Guide is here to assist you with all parish services, Mass schedules, sacraments, certificate requirements, and requests. How can I help you on this blessed day?",
                "Good morning, and may your day be blessed! ☀️ How may I assist you with parish records, Mass times, or sacramental guidelines today?"
            ];
        }
        if ($specificIntent === self::GOOD_AFTERNOON || ($specificIntent === self::GREETING && $period === 'afternoon')) {
            return [
                "Good afternoon! Peace be with you. 🕊️ TUGON Parish Guide is here to assist you with parish services, Mass schedules, sacraments, and certificate requests. How may I help you today?",
                "Good afternoon! May your day be blessed. How can I assist you with church records or services this afternoon?"
            ];
        }
        if ($specificIntent === self::GOOD_EVENING || ($specificIntent === self::GREETING && ($period === 'evening' || $period === 'night'))) {
            return [
                "Good evening! Peace be with you. 🌙 TUGON Parish Guide is here to assist you with sacramental inquiries, Mass schedules, or request tracking. How may I assist you tonight?",
                "Good evening! May your night be peaceful and blessed. How can I help you with parish services or inquiries?"
            ];
        }

        return [
            "Hello, and peace be with you! 🕊️ I'm your TUGON Parish Guide. How may I assist you with sacraments, certificate requests, or Mass schedules today?",
            "Greetings in Christ! TUGON Parish Guide is at your service for parish records, schedules, and document requirements. How can I help you today?"
        ];
    }

    private static function howAreYouResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Mabuti naman po, salamat sa pagtatanong! 😊 Handa po akong tumulong sa inyong mga tanong tungkol sa parish services at requirements.",
                "Maayos naman po ako at masayang makatulong sa inyo! Paano po kita matutulungan sa mga serbisyo ng parokya?"
            ];
        }
        return [
            "I'm doing well, thank you! 😊 I'm here and ready to help with your parish-related questions."
        ];
    }

    private static function thanksResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Walang anuman po! 🙏 Masaya po akong makatulong. Sabihan lang po ako kung may iba pa kayong katanungan.",
                "Walang anuman po! 😊 Kung may kailangan pa po kayo tungkol sa parokya, nandito lang po ako.",
                "Malugod ko po kayong pinaglilingkuran. God bless po! 🙏"
            ];
        }
        return [
            "You're very welcome! 🙏 I'm happy to help.",
            "You're welcome! If you have another question, feel free to ask.",
            "Glad I could help! 😊 Let me know if there's anything else you need."
        ];
    }

    private static function okayResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Alright po! 😊 Sabihan lang po ako kung may maitutulong pa po ako.",
                "Sige po! 😊 Sabihan lang po ako kung may maitutulong pa po ako."
            ];
        }
        return [
            "Alright! 😊 Let me know if there's anything else I can help you with.",
            "Alright! 😊 Feel free to ask anytime if you need further assistance."
        ];
    }

    private static function niceResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Salamat po! 😊 Masaya po akong nakatulong sa inyo.",
                "Salamat po! 😊 Ikinagagalak ko pong makatulong sa inyo."
            ];
        }
        return [
            "Thank you! 😊 I'm glad I could help.",
            "Thank you! 😊 I'm happy to help you with parish services anytime."
        ];
    }

    private static function goodNightResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Good night po! 🙏 Magkaroon po sana kayo ng mapayapang pamamahinga. God bless po!",
                "Magandang gabi at payapang pamamahinga po! 🙏 Kung may kailangan pa po kayo bukas, nandito lang po ang TUGON AI."
            ];
        }
        return [
            "Good night! 🙏 May you have a peaceful rest. If you still need assistance, I'm here to help."
        ];
    }

    private static function farewellResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Paalam po! Mag-ingat po kayo lagi at God bless! 🙏",
                "Salamat po sa pakikipag-ugnayan sa TUGON. Have a blessed day! 🙏"
            ];
        }
        return [
            "Goodbye! Take care and God bless! 🙏",
            "Thank you for contacting TUGON. Have a blessed day ahead! 🙏"
        ];
    }

    private static function whoAreYouResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Ako po si TUGON AI, ang matalinong assistant ng San Lorenzo Ruiz Mission Station (TUGON Parish System). Makakatulong po ako sa paghahanap ng impormasyon sa parokya, mga requirements sa sakramento (binyag, kasal, kumpil, atbp.), mga proseso sa certificate request, iskedyul ng misa, oras ng opisina, at mga anunsyo."
            ];
        }
        return [
            "I’m TUGON AI, the intelligent assistant of the TUGON Parish System (San Lorenzo Ruiz Mission Station). I can help you find parish information, understand sacramental requirements, learn about requests and services, check available schedules, and guide you through the TUGON system."
        ];
    }

    private static function capabilitiesResponses($language)
    {
        if ($language === 'fil' || $language === 'taglish') {
            return [
                "Narito po ang mga maaari kong maitulong sa inyo sa TUGON:\n\n" .
                "• **Mga Sakramento**: Requirements at proseso para sa Binyag, Kumpil, Kasal, First Holy Communion, Anointing of the Sick, at Funeral Mass.\n" .
                "• **Mga Pagbabasbas**: House Blessing at Vehicle Blessing.\n" .
                "• **Certificate Requests**: Paano kumuha ng Baptismal, Confirmation, o Marriage Certificates.\n" .
                "• **Iskedyul at Oras**: Iskedyul ng Misa, Parish Office Hours, at Emergency Contacts.\n" .
                "• **Reservations**: Gabay sa pag-reserve ng pasilidad o serbisyo.\n" .
                "• **Status Tracking**: Pagsusuri sa katayuan ng inyong mga naisumiteng kahilingan.\n\n" .
                "Ano po sa mga ito ang nais ninyong malaman?"
            ];
        }
        return [
            "Here is what I can help you with in the TUGON Parish System:\n\n" .
            "• **Sacramental Services**: Requirements and preparation steps for Baptism, Confirmation, Marriage, First Holy Communion, Anointing of the Sick, and Funeral Mass.\n" .
            "• **Parish Blessings**: House Blessing and Vehicle Blessing requests.\n" .
            "• **Certificates**: Requirements and submission process for Baptismal, Confirmation, and Marriage Certificates.\n" .
            "• **Schedules & Office Info**: Sunday/Weekday Mass times, Parish Office Hours, and Emergency Contacts.\n" .
            "• **Reservations**: Facility and event booking guidance.\n" .
            "• **Request Tracking**: Checking the status of your submitted service or certificate requests.\n\n" .
            "Which service or topic would you like to explore?"
        ];
    }

    private static function withResponse(array $result, string $intent, array $responses, bool $isPureSocial = false, array $prompts = []): array
    {
        $result['intent'] = $intent;
        $result['is_pure_social'] = $isPureSocial;
        $result['response'] = self::pick($responses, $result['normalized']);
        $result['suggested_prompts'] = $prompts;
        return $result;
    }

    private static function pick(array $responses, string $seed): string
    {
        if (count($responses) === 1) {
            return $responses[0];
        }
        try {
            return $responses[random_int(0, count($responses) - 1)];
        } catch (Throwable $e) {
            return $responses[abs(crc32((string) $seed)) % count($responses)];
        }
    }
}
