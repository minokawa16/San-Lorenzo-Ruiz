<?php
/**
 * Lightweight conversational intent layer for TUGON AI.
 *
 * Pure social messages are answered here without database retrieval or an
 * Ollama request. Messages that contain a greeting plus a real question are
 * deliberately left for the existing RAG pipeline.
 */
final class TugonConversationalIntent
{
    const GREETING = 'GREETING';
    const HOW_ARE_YOU = 'HOW_ARE_YOU';
    const THANKS = 'THANKS';
    const FAREWELL = 'FAREWELL';
    const ABOUT_ASSISTANT = 'ABOUT_ASSISTANT';

    public static function analyze($message, DateTimeInterface $now = null)
    {
        $normalized = self::normalize($message);
        $language = self::detectLanguage($normalized);
        $period = self::currentPeriod($now);
        $result = [
            'intent' => null,
            'normalized' => $normalized,
            'language' => $language,
            'period' => $period,
            'greeting_detected' => false,
            'greeting_acknowledgement' => null,
            'response' => null,
        ];

        if ($normalized === '') {
            return $result;
        }

        if (preg_match('/^(?:thank you(?: so much)?|thanks(?: a lot)?|salamat|maraming salamat)(?: po)?\s+(?:bye|goodbye|see you(?: later)?|good night)(?: po)?$/', $normalized)
            || preg_match('/^(?:bye|goodbye|see you(?: later)?|good night|okay bye|ok bye|sige po)(?: po)?$/', $normalized)) {
            return self::withResponse($result, self::FAREWELL, self::farewellResponses($language));
        }

        if (preg_match('/^(?:how are you(?: doing)?|how s it going)(?: po)?(?: tugon(?: ai)?)?$/', $normalized)
            || preg_match('/^(?:kumusta|kamusta) ka(?: po)?(?: tugon(?: ai)?)?$/', $normalized)) {
            return self::withResponse($result, self::HOW_ARE_YOU, self::statusResponses($language));
        }

        if (preg_match('/^(?:thank you(?: so much)?|thanks(?: a lot)?|salamat|maraming salamat)(?: po)?$/', $normalized)) {
            return self::withResponse($result, self::THANKS, self::thanksResponses($language));
        }

        if (preg_match('/^(?:who are you|what are you|what is tugon(?: ai)?|who is tugon(?: ai)?|tell me about yourself|what can you do|what can you help me with)(?: po)?$/', $normalized)) {
            return self::withResponse($result, self::ABOUT_ASSISTANT, self::aboutResponses($language));
        }

        $greeting = self::extractLeadingGreeting($normalized);
        if ($greeting !== null) {
            $result['greeting_detected'] = true;
            $result['greeting_acknowledgement'] = self::greetingAcknowledgement($language, $period);

            if ($greeting['remainder'] === '') {
                return self::withResponse($result, self::GREETING, self::greetingResponses($language, $period));
            }
        }

        return $result;
    }

    public static function normalize($message)
    {
        $text = mb_strtolower(trim((string) $message), 'UTF-8');
        $text = str_replace(['’', "'"], ' ', $text);
        $text = preg_replace('/\bhel+o+\b/u', 'hello', $text);
        $text = preg_replace('/\bh+i+\b/u', 'hi', $text);
        $text = preg_replace('/\bhe+y+\b/u', 'hey', $text);
        $text = preg_replace('/\bgood\s*morning\b/u', 'good morning', $text);
        $text = preg_replace('/\bgood\s*afternoon\b/u', 'good afternoon', $text);
        $text = preg_replace('/\bgood\s*evening\b/u', 'good evening', $text);
        $text = preg_replace('/\bgood\s*night\b/u', 'good night', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $text);
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function extractLeadingGreeting($normalized)
    {
        $pattern = '/^(?:hello|hi|hey)(?: there)?(?: po)?(?: tugon(?: ai)?)?\b|^(?:good (?:morning|afternoon|evening)|morning|afternoon|evening)(?: po)?(?: tugon(?: ai)?)?\b|^(?:(?:kumusta|kamusta)(?: po)?|magandang (?:umaga|hapon|gabi)(?: po)?)(?: tugon(?: ai)?)?\b|^(?:nice to (?:meet|see) you)(?: po)?\b/';
        if (!preg_match($pattern, $normalized, $match)) {
            return null;
        }

        $remainder = trim(substr($normalized, strlen($match[0])));
        $remainder = preg_replace('/^(?:and|at|pero|but)\s+/', '', $remainder);
        return ['greeting' => $match[0], 'remainder' => trim((string) $remainder)];
    }

    private static function detectLanguage($normalized)
    {
        if (preg_match('/\b(?:kumusta|kamusta|magandang|salamat|maraming|paano|ano|saan|ako|ka|po)\b/u', $normalized)) {
            return 'fil';
        }
        return 'en';
    }

    private static function currentPeriod(DateTimeInterface $now = null)
    {
        if ($now === null) {
            $timezoneName = getenv('APP_TIMEZONE') ?: 'Asia/Manila';
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone($timezoneName));
            } catch (Throwable $exception) {
                $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            }
        }

        $hour = intval($now->format('G'));
        if ($hour >= 5 && $hour < 12) {
            return 'morning';
        }
        if ($hour >= 12 && $hour < 18) {
            return 'afternoon';
        }
        return 'evening';
    }

    private static function greetingAcknowledgement($language, $period)
    {
        if ($language === 'fil') {
            $labels = ['morning' => 'Magandang umaga po!', 'afternoon' => 'Magandang hapon po!', 'evening' => 'Magandang gabi po!'];
            return $labels[$period];
        }
        $labels = ['morning' => 'Good morning po!', 'afternoon' => 'Good afternoon po!', 'evening' => 'Good evening po!'];
        return $labels[$period];
    }

    private static function greetingResponses($language, $period)
    {
        $greeting = self::greetingAcknowledgement($language, $period);
        if ($language === 'fil') {
            return [
                $greeting . ' 😊 Paano ko po kayo matutulungan?',
                $greeting . ' Welcome sa TUGON. Ano po ang maitutulong ko sa inyo?',
                $greeting . ' Handa po akong tumulong sa inyong mga tanong tungkol sa parish services.',
                'Kumusta po! 😊 Maaari po ninyo akong tanungin tungkol sa mga serbisyo ng ating parish.',
            ];
        }
        return [
            $greeting . ' 😊 How may I assist you today?',
            $greeting . ' Welcome to TUGON. How can I help you?',
            'Hello po! I’m TUGON AI. How may I assist you with your parish-related needs?',
            'Good to see you! 😊 What parish service can I help you with today?',
        ];
    }

    private static function statusResponses($language)
    {
        if ($language === 'fil') {
            return [
                'Mabuti naman po, salamat sa pagtatanong! 😊 Handa po akong tumulong sa inyong mga tanong tungkol sa parish services.',
                'Maayos po akong gumagana at handang tumulong. Ano po ang nais ninyong malaman tungkol sa parish?',
            ];
        }
        return [
            'I’m operating well, thank you for asking! 😊 I’m ready to assist with your parish-related questions.',
            'Everything is working well, thank you! How may I help you with parish services today?',
        ];
    }

    private static function thanksResponses($language)
    {
        if ($language === 'fil') {
            return [
                'Walang anuman po! 😊 Kung mayroon pa po kayong katanungan, maaari po kayong magtanong.',
                'Malugod ko po kayong tinutulungan. Sabihin lang po kung may kailangan pa kayo.',
                'Walang anuman po. Nandito lang ang TUGON AI kung may iba pa kayong tanong.',
            ];
        }
        return [
            'You’re very welcome po! 😊 I’m always happy to help.',
            'You’re welcome po! Let me know if you need anything else.',
            'Glad I could help. Please feel free to ask another parish-related question.',
        ];
    }

    private static function farewellResponses($language)
    {
        if ($language === 'fil') {
            return [
                'Salamat po! Ingat po kayo, and God bless! 🙏',
                'Paalam po. Have a blessed day! 🙏',
                'Sige po, ingat kayo. God bless!',
            ];
        }
        return [
            'Goodbye po! God bless you. 🙏',
            'Thank you po. Have a blessed day!',
            'Take care po, and please feel welcome to return anytime.',
        ];
    }

    private static function aboutResponses($language)
    {
        if ($language === 'fil') {
            return [
                'Ako ang TUGON AI, ang virtual assistant ng parish. Makakatulong ako sa parish services, sacramental at certificate requests, requirements, announcements, reservations, schedules, at iba pang verified information sa parish knowledge base.',
                'Ang TUGON AI ay parish virtual assistant. Maaari ninyo akong tanungin tungkol sa requests, certificates, sacraments, schedules, announcements, at reservations.',
            ];
        }
        return [
            'I am TUGON AI, the parish virtual assistant. I can help with parish services, sacramental and certificate requests, requirements, announcements, reservations, schedules, and other verified information in the parish knowledge base.',
            'TUGON AI is your parish virtual assistant. I can guide you through requests, certificates, sacraments, schedules, announcements, and reservations.',
        ];
    }

    private static function withResponse(array $result, $intent, array $responses)
    {
        $result['intent'] = $intent;
        $result['response'] = self::pick($responses, $result['normalized']);
        return $result;
    }

    private static function pick(array $responses, $seed)
    {
        if (count($responses) === 1) {
            return $responses[0];
        }
        try {
            return $responses[random_int(0, count($responses) - 1)];
        } catch (Throwable $exception) {
            return $responses[abs(crc32((string) $seed)) % count($responses)];
        }
    }
}
