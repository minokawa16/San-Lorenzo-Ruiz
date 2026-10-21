<?php
/**
 * TUGON AI scope configuration (single source of truth).
 *
 * Used ONLY by includes/chatbot/ScopeGate.php. To add/remove allowed or blocked words,
 * edit the lists below. Keep every entry lowercase, ASCII, no accents.
 *
 * Matching rules (see ScopeGate::inScope):
 *   - Message is normalized: lowercase, accents removed, punctuation -> spaces.
 *   - An 'allow' word matches a message token if equal, or (for words of 6+ letters)
 *     if the token starts with it (certificate -> certificates, requirement -> requirements).
 *   - 'block_words' / 'block_phrases' ALWAYS win over the allowlist (so "how to cook adobo
 *     for the parish feast" is refused).
 *   - Anything not on the allowlist is refused (refuse-by-default).
 */
return [
    // Bump when the gate changes. Returned in the API header X-Tugon-Scope-Gate and JSON field scope_gate.
    'version' => '20261022',

    // Fixed refusal, exactly as approved by the parish. Never edit per-request.
    'refusal' => [
        'en'  => "I only respond to TUGON Parish related questions. I can help with certificates, sacramental services, blessings, schedules and your requests.",
        'fil' => "Tungkol lamang sa TUGON Parish ang masasagot ko. Matutulungan ko kayo sa mga sertipiko, sakramento, basbas, iskedyul at inyong mga request.",
        'ceb' => "Mga pangutana lang nga may kalabotan sa TUGON Parish ang matubag nako. Makatabang ko sa mga sertipiko, sakramento, basbas, iskedyul ug inyong mga request.",
    ],

    // Shown only for the optional "is this about the parish?" clarification (in-scope but no data).
    'clarify' => [
        'en'  => "Is this about a parish service or request? I can help with certificates, sacramental services, blessings, schedules and your requests.",
        'fil' => "Tungkol po ba ito sa isang serbisyo o kahilingan sa parokya? Matutulungan ko po kayo sa mga sertipiko, sakramento, basbas, iskedyul at inyong mga request.",
        'ceb' => "Mahitungod ba kini sa serbisyo o hangyo sa parokya? Makatabang ko sa mga sertipiko, sakramento, basbas, iskedyul ug inyong mga request.",
    ],

    // ALLOWLIST: a message is in scope only if it contains one of these (or is a greeting / short follow-up).
    'allow' => [
        // the parish and people
        'parish', 'parokya', 'simbahan', 'church', 'chapel', 'kapilya', 'priest', 'pari', 'paroko', 'vicar', 'secretary',
        'father', 'padre', 'fr', 'cahilig', 'barretto', 'alvin', 'alberto', 'slrms', 'san lorenzo', 'lorenzo',
        // certificates
        'certificate', 'certification', 'sertipiko', 'sertipikasyon',
        // sacraments / services
        'baptism', 'baptismal', 'baptize', 'christening', 'bunyag', 'binyag', 'pabunyag', 'pabinyag',
        'confirmation', 'kumpil', 'pakumpil',
        'communion', 'kumunyon', 'komunyon',
        'wedding', 'marriage', 'kasal', 'papakasal', 'pakasal',
        'funeral', 'libing', 'burol', 'lubong',
        'sacrament', 'sakramento', 'sacramental', 'confession', 'kumpisal', 'kompisal', 'reconciliation', 'anointing',
        // blessings
        'bless', 'blessing', 'basbas', 'pabasbas', 'bendisyon',
        // schedules / calendar / mass
        'mass', 'misa', 'pamisa', 'schedule', 'iskedyul', 'calendar', 'kalendaryo', 'novena', 'nobena', 'rosary', 'rosaryo', 'adoration',
        // requests and records
        'request', 'kahilingan', 'requirement', 'kailangan', 'rekisitos', 'kinahanglan',
        'fee', 'fees', 'bayad', 'payment', 'gcash', 'status', 'record', 'talaan',
        'appointment', 'reservation', 'sponsor', 'godparent', 'ninong', 'ninang', 'seminar',
        // account / system use
        'account', 'login', 'register', 'tugon', 'otp', 'password', 'announcement', 'anunsyo', 'organization', 'analytics',
        // office
        'office', 'opisina',
        // pastoral emergencies (must reach the pastoral-care guardrail)
        'dying', 'naghihingalo', 'namatay', 'passed away', 'lastrites', 'last rites',
    ],

    // Hard blockers. If ANY appears, refuse even when a parish word is present.
    'block_words' => [
        'cook', 'cooking', 'cooked', 'recipe', 'recipes', 'adobo', 'sinigang', 'tinola', 'luto', 'magluto', 'pagluto', 'lutuin',
        'bake', 'baking', 'cake',
        'nba', 'basketball', 'football', 'soccer', 'volleyball',
        'movie', 'movies', 'netflix',
        'poem', 'poems', 'poetry', 'joke', 'jokes', 'riddle',
        'math', 'algebra', 'solve', 'equation',
        'laptop', 'iphone', 'python', 'javascript', 'programming', 'coding',
        'bitcoin', 'crypto', 'horoscope', 'homework',
        'jailbreak',
    ],
    'block_phrases' => [
        'ignore your rules', 'ignore all rules', 'ignore the rules', 'ignore previous', 'ignore all previous',
        'forget the parish', 'forget about the parish', 'forget your rules',
        'you are now', 'pretend to be', 'act as a', 'general assistant', 'answer anything',
        'capital of', 'who won', 'weather in', 'how do i fix', 'system prompt',
    ],

    // Greetings / thanks / "what can you do": allowed ONLY when every word is in this vocabulary (max 8 words).
    'greeting_words' => [
        'hi', 'hello', 'hey', 'hola', 'good', 'morning', 'afternoon', 'evening', 'night', 'magandang', 'umaga', 'hapon', 'gabi',
        'kumusta', 'kamusta', 'musta', 'ka', 'kayo', 'po', 'opo', 'you', 'thank', 'thanks', 'salamat', 'maraming', 'daghang',
        'tugon', 'ai', 'there', 'ok', 'okay', 'sige', 'amen', 'bye', 'goodbye', 'paalam',
        'help', 'ayuda', 'tabang', 'please', 'pls', 'who', 'are', 'what', 'can', 'do', 'how',
    ],

    // Short follow-ups ("how much?") are allowed only right after an in-scope answer, and only if the message
    // is <= followup_max_chars AND contains at least one of these ordinary follow-up words (blocks gibberish).
    'followup_max_chars' => 40,
    'followup_words' => [
        'how', 'what', 'when', 'where', 'who', 'which', 'much', 'many', 'long', 'about', 'and', 'also', 'then', 'else', 'more',
        'please', 'po', 'ano', 'paano', 'kailan', 'saan', 'sino', 'magkano', 'gaano', 'pila', 'tagpila', 'unsa', 'unsaon', 'asa', 'kinsa',
        'yes', 'no', 'oo', 'opo', 'hindi', 'ok', 'okay', 'sige', 'service', 'for', 'in', 'on', 'it', 'that', 'this', 'ba', 'pa',
        'steps', 'step', 'time', 'oras', 'date', 'petsa', 'free', 'available', 'cost', 'price', 'process', 'need', 'needed',
    ],

    // Knowledge search must score at least this to count as a match (never return "closest result").
    'min_kb_score' => 5.0,

    // Words ignored by the knowledge-base scorer so "how to ..." can never match a "How to Request..." record.
    'kb_stopwords' => [
        'how', 'to', 'do', 'does', 'did', 'i', 'we', 'you', 'the', 'a', 'an', 'of', 'for', 'is', 'are', 'was', 'what', 'whats', 'can',
        'my', 'me', 'in', 'on', 'at', 'and', 'or', 'it', 'be', 'get', 'with', 'about', 'please', 'po', 'ba', 'ang', 'ng', 'sa', 'mga',
        'ako', 'ko', 'mo', 'ano', 'paano', 'pano', 'unsa', 'unsaon', 'ug', 'og', 'nga', 'na', 'pa', 'naman', 'lang',
    ],
];
