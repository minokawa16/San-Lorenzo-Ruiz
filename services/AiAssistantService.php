<?php

require_once __DIR__ . '/../includes/audit.php';

final class AiAssistantService
{
    private mysqli $db;
    private const UNKNOWN_EN = 'I do not have enough current, approved parish information to answer that accurately. Please confirm with the parish administrator.';
    private const UNKNOWN_FIL = 'Wala akong sapat na kasalukuyan at aprubadong impormasyon para sagutin ito nang tama. Mangyaring kumpirmahin sa administrador ng parokya.';

    public function __construct(mysqli $db) { $this->db = $db; }

    public function respond(int $userId, array $capabilities, string $message, string $mode, array $conversation = []): array
    {
        $message = trim(mb_strimwidth($message, 0, 1000, ''));
        if ($message === '') throw new InvalidArgumentException('Please enter a question.');
        $language = $this->language($message);
        $correlation = tugonCorrelationId();
        $audience = !empty($capabilities['staff']) ? 'staff' : 'parishioner';

        if ($this->isInjection($message)) {
            $answer = $language === 'fil'
                ? 'Hindi ko maaaring balewalain ang mga patakaran, maglabas ng lihim, o lampasan ang pahintulot. Maaari kitang tulungan sa mga awtorisadong serbisyo ng TUGON.'
                : 'I cannot ignore safeguards, reveal secrets, or bypass permissions. I can help with authorized TUGON parish services.';
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], [], null, $correlation, 'security-refusal');
        }
        if ($this->requestsMutation($message)) {
            $answer = $language === 'fil'
                ? 'Read-only ang TUGON AI at hindi ito maaaring magbago, mag-apruba, mag-isyu, o magtanggal ng tala. Gamitin ang awtorisadong workflow sa dashboard.'
                : 'TUGON AI is read-only and cannot change, approve, issue, or delete records. Use the authorized dashboard workflow for that action.';
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], [], null, $correlation, 'read-only-refusal');
        }
        if (!$this->isParishRelated($message) && $mode !== 'search') {
            $answer=$language==='fil'?'Nakatuon ang TUGON AI sa mga serbisyo, iskedyul, sakramento, sertipiko, at kahilingan ng parokya. Magtanong tungkol sa alinman sa mga ito.':'TUGON AI is limited to parish services, schedules, sacraments, certificates, requests, and reservations. Please ask about one of those topics.';
            return $this->persist($userId,$audience,$mode,$language,$message,$answer,[],[],null,$correlation,'topic-refusal');
        }

        $searchResults = $this->searchOwnedOrAuthorizedData($userId, $capabilities, $message);
        $analytics = null;
        if ($mode === 'analytics' || preg_match('/\b(report|analytics|summary|ulat|buod)\b/i', $message)) {
            if (empty($capabilities['reports'])) {
                $answer = $language === 'fil' ? 'Wala kang pahintulot na tingnan ang ulat na ito.' : 'You do not have permission to view that report.';
                return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], $searchResults, null, $correlation, 'permission-refusal');
            }
            $analytics = $this->analytics($capabilities);
            $answer = $language === 'fil' ? 'Narito ang awtorisadong buod batay sa kasalukuyang tala.' : 'Here is the authorized summary based on current records.';
            return $this->persist($userId, $audience, 'analytics', $language, $message, $answer, [], $searchResults, $analytics, $correlation, 'authorized-analytics');
        }

        $sources = $this->knowledge($message);
        if (!$sources) {
            $answer = $language === 'fil' ? self::UNKNOWN_FIL : self::UNKNOWN_EN;
            return $this->persist($userId, $audience, $mode, $language, $message, $answer, [], $searchResults, null, $correlation, 'grounded-unknown');
        }

        $primary = $sources[0];
        $answer = $language === 'fil'
            ? "Narito ang kasalukuyang opisyal na impormasyon (pinanatili ang eksaktong opisyal na mga termino):\n\n" . $primary['content']
            : $primary['content'];
        if (!empty($primary['steps'])) $answer .= "\n\n" . $primary['steps'];
        $answer .= "\n\nSource: " . $primary['title'] . "\nLast updated: " . date('F j, Y', strtotime($primary['updated_at']));
        return $this->persist($userId, $audience, $mode, $language, $message, $answer, $sources, $searchResults, null, $correlation, 'approved-knowledge');
    }

    public function saveFeedback(int $reviewerId, string $reference, string $rating, string $comments): void
    {
        if (!in_array($rating, ['correct','incorrect','needs_review'], true)) throw new InvalidArgumentException('Invalid feedback value.');
        $comments = trim(mb_strimwidth(tugonRedactSensitive($comments), 0, 1000, ''));
        $stmt = $this->db->prepare('SELECT response_id,source_snapshot FROM ai_responses WHERE response_reference=? LIMIT 1');
        $stmt->bind_param('s', $reference); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) throw new DomainException('AI response not found.');
        $responseId = (int)$row['response_id']; $snapshot = $row['source_snapshot'];
        $stmt = $this->db->prepare('INSERT INTO ai_feedback(response_id,rating,comments,reviewer_user_id,knowledge_source_snapshot)
            VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rating=VALUES(rating),comments=VALUES(comments),knowledge_source_snapshot=VALUES(knowledge_source_snapshot),updated_at=CURRENT_TIMESTAMP');
        $stmt->bind_param('issis', $responseId, $rating, $comments, $reviewerId, $snapshot);
        if (!$stmt->execute()) throw new RuntimeException('Unable to save feedback.');
        $stmt->close();
        writeAuditLog($this->db, $reviewerId, 'AI_FEEDBACK_SUBMITTED', 'ai_responses', $responseId, null, ['rating'=>$rating], 'ai', 'ai.feedback');
    }

    private function knowledge(string $query): array
    {
        $search = preg_replace('/[^\pL\pN\s-]+/u', ' ', $query);
        $boolean = implode(' ', array_map(static fn($w) => $w . '*', array_slice(array_filter(preg_split('/\s+/', $search), static fn($w)=>mb_strlen($w)>=3), 0, 8)));
        $rows = [];
        if ($boolean !== '') {
            $stmt = $this->db->prepare("SELECT knowledge_id,topic,keywords,answer,steps,category,source,version,effective_date,expiry_date,language,updated_at,
                MATCH(topic,keywords,answer) AGAINST(? IN BOOLEAN MODE) score
                FROM chatbot_knowledge WHERE status='active' AND approval_status='approved'
                AND (effective_date IS NULL OR effective_date<=CURRENT_DATE) AND (expiry_date IS NULL OR expiry_date>=CURRENT_DATE)
                AND MATCH(topic,keywords,answer) AGAINST(? IN BOOLEAN MODE) HAVING score>=1.0 ORDER BY score DESC,updated_at DESC LIMIT 3");
            $stmt->bind_param('ss', $boolean, $boolean); $stmt->execute(); $result=$stmt->get_result();
            while ($row=$result->fetch_assoc()) $rows[]=$row; $stmt->close();
        }
        $rows=array_values(array_filter($rows,fn($row)=>$this->knowledgeRelevant($query,$row)));
        return array_map(static fn($row)=>[
            'title'=>$row['topic'], 'category'=>$row['category'], 'content'=>$row['answer'], 'steps'=>$row['steps'],
            'source'=>$row['source'], 'version'=>(int)$row['version'], 'updated_at'=>$row['updated_at']
        ], $rows);
    }

    private function knowledgeRelevant(string $query,array $row):bool
    {
        $stop=['what','where','when','which','with','from','that','this','official','policy','requirements','requirement','need','needed','please','about','paano','kailangan','mga','ang','ano','para','opisyal'];
        $tokens=array_values(array_unique(array_filter(preg_split('/[^\pL\pN]+/u',mb_strtolower($query)),static fn($w)=>mb_strlen($w)>=3&&!in_array($w,$stop,true))));
        if(!$tokens)return false;$source=mb_strtolower(($row['topic']??'').' '.($row['keywords']??'').' '.($row['category']??''));$matched=0;
        foreach($tokens as $token){$stem=rtrim($token,'s');if(mb_strpos($source,$token)!==false||($stem!==''&&mb_strpos($source,$stem)!==false))$matched++;}
        return ($matched/count($tokens))>=0.6;
    }

    private function searchOwnedOrAuthorizedData(int $userId, array $caps, string $query): array
    {
        $items=[]; $term='%' . mb_strimwidth($query,0,120,'') . '%';
        if (!empty($caps['records'])) {
            $stmt=$this->db->prepare("SELECT request_id,reference_number,request_type,status,date_requested FROM requests WHERE deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 8");
            $stmt->bind_param('ss',$term,$term);
        } else {
            $stmt=$this->db->prepare("SELECT request_id,reference_number,request_type,status,date_requested FROM requests WHERE user_id=? AND deleted_at IS NULL AND (reference_number LIKE ? OR request_type LIKE ?) ORDER BY date_requested DESC LIMIT 8");
            $stmt->bind_param('iss',$userId,$term,$term);
        }
        $stmt->execute(); $result=$stmt->get_result();
        while($row=$result->fetch_assoc()) $items[]=['module'=>'Request','title'=>$row['reference_number'] ?: ucwords(str_replace('_',' ',$row['request_type'])),'meta'=>ucfirst($row['status']),'url'=>!empty($caps['staff'])?'../admin/manage-requests.php':'../users/view-request.php?id='.(int)$row['request_id']];
        $stmt->close(); return $items;
    }

    private function analytics(array $caps): array
    {
        $metrics=[];
        if (!empty($caps['reports'])) {
            foreach (["Pending Requests"=>"SELECT COUNT(*) c FROM requests WHERE deleted_at IS NULL AND status NOT IN ('completed','rejected','cancelled')", "Open Reservations"=>"SELECT COUNT(*) c FROM reservations WHERE status IN ('pending','approved')", "Certificates Issued"=>"SELECT COUNT(*) c FROM certificate_issuances WHERE status IN ('issued','released','reissued')"] as $label=>$sql) {
                $metrics[$label]=(int)($this->db->query($sql)->fetch_assoc()['c']??0);
            }
        }
        return ['metrics'=>$metrics,'insights'=>['Counts are current and permission-scoped. Open Reports for date filters and export.']];
    }

    private function persist(int $userId,string $audience,string $mode,string $language,string $question,string $answer,array $sources,array $results,?array $analytics,string $correlation,string $provider): array
    {
        $hex=bin2hex(random_bytes(16));
        $reference=sprintf('%s-%s-%s-%s-%s',substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20));
        $question=tugonRedactSensitive($question); $answer=tugonRedactSensitive($answer);
        $publicSources=array_map(static fn($s)=>['title'=>$s['title'],'source'=>$s['source'],'version'=>$s['version'],'last_updated'=>date('F j, Y',strtotime($s['updated_at']))],$sources);
        $snapshot=json_encode($publicSources, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        $stmt=$this->db->prepare('INSERT INTO ai_responses(response_reference,user_id,audience,mode,language,question_redacted,answer_redacted,source_snapshot,provider,correlation_id) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sissssssss',$reference,$userId,$audience,$mode,$language,$question,$answer,$snapshot,$provider,$correlation); $stmt->execute(); $stmt->close();
        $stmt=$this->db->prepare('INSERT INTO chatbot_inquiries(user_id,user_role,question,answer_preview,mode,context_limited,correlation_id,response_reference) VALUES(?,?,?,?,?,1,?,?)');
        $stmt->bind_param('issssss',$userId,$audience,$question,$answer,$mode,$correlation,$reference); $stmt->execute(); $stmt->close();
        return ['success'=>true,'answer'=>$answer,'guidance'=>['title'=>'TUGON AI','steps'=>[]],'sources'=>$publicSources,'search_results'=>$results,'analytics'=>$analytics,'language'=>$language,'response_reference'=>$reference,'correlation_id'=>$correlation,'escalation'=>['label'=>'Contact Parish Staff','url'=>'../users/request-service.php']];
    }

    private function language(string $text): string { return preg_match('/\b(ano|paano|saan|kailan|magkano|kailangan|parokya|binyag|kasal|po|ba)\b/iu',$text)?'fil':'en'; }
    private function isInjection(string $text): bool { return (bool)preg_match('/ignore (all |the )?(previous|system)|reveal (the )?(prompt|secret|credential)|bypass (permission|authorization)|execute (sql|command)|database password|session (id|token)/i',$text); }
    private function requestsMutation(string $text): bool { return (bool)preg_match('/\b(update|delete|approve|reject|issue|revoke|publish|modify|change)\b.{0,40}\b(record|request|certificate|payment|reservation|announcement|database)\b/i',$text); }
    private function isParishRelated(string $text):bool { return (bool)preg_match('/parish|parokya|church|mass|misa|office|opisina|bapt|binyag|confirm|kumpil|communion|komunyon|marriage|wedding|kasal|bless|basbas|certificate|sertipiko|request|kahilingan|reserv|venue|schedule|iskedyul|announcement|anunsyo|payment|bayad|funeral|burial|libing|priest|pari|record|tala|sacrament|analytics|report|ulat|TUGON/i',$text); }
}
