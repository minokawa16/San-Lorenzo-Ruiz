<?php

/**
 * Authoritative sacramental-record write service (Phase 8).
 * All table and column names come from the fixed configuration below; no caller
 * controlled identifier is ever interpolated into SQL.
 */
final class SacramentalRecordService
{
    private $db;

    private const TYPES = [
        'baptism' => [
            'table' => 'baptism_records', 'id' => 'baptism_id',
            'name' => ['fullname'], 'birth' => ['birth_date'], 'event' => 'baptism_date',
            'required' => ['fullname','birth_date','birth_place','parents','baptism_date','godparents','priest'],
            'request_types' => ['baptism_service','baptismal_certificate'],
            'fields' => ['request_id','registry_no','book_no','page_no','entry_no','fullname','birth_date','birth_place','birth_status','parents','parent_address','baptism_date','godparents','parish_address','priest','remarks','parish_priest','parish_secretary'],
        ],
        'confirmation' => [
            'table' => 'confirmation_records', 'id' => 'confirmation_id',
            'name' => ['fullname'], 'birth' => ['birth_date'], 'event' => 'confirmation_date',
            'required' => ['fullname','birth_date','confirmation_date','parents','sponsor','bishop_priest'],
            'request_types' => ['confirmation_certificate'],
            'fields' => ['request_id','registry_no','book_no','page_no','entry_no','fullname','birth_date','confirmation_date','confirmation_name','age','origin_parish','origin_province','baptismal_place','parents','sponsor','bishop_priest','stipend_pesos','stipend_cents','observations','parish_priest','parish_secretary'],
        ],
        'communion' => [
            'table' => 'first_communion_records', 'id' => 'communion_id',
            'name' => ['fullname'], 'birth' => ['birth_date'], 'event' => 'communion_date',
            'required' => ['fullname','birth_date','communion_date','parents','priest'],
            'request_types' => ['first_communion_certificate'],
            'fields' => ['request_id','registry_no','book_no','page_no','entry_no','fullname','birth_date','communion_date','domicile','parents','sponsor','priest','folio','baptismal_date','baptismal_place','remarks','parish_priest','parish_secretary'],
        ],
        'marriage' => [
            'table' => 'marriage_records', 'id' => 'marriage_id',
            'name' => ['husband_name','wife_name'], 'birth' => ['husband_birth_date','wife_birth_date'], 'event' => 'wedding_date',
            'required' => ['husband_name','wife_name','husband_birth_date','wife_birth_date','wedding_date','wedding_location','sponsors','officiating_priest'],
            'request_types' => ['marriage_certificate','marriage_wedding_service'],
            'fields' => ['request_id','registry_no','book_no','page_no','entry_no','husband_name','husband_birth_date','husband_status','husband_age','husband_birth_origin','husband_residence','husband_parents','wife_name','wife_birth_date','wife_status','wife_age','wife_birth_origin','wife_residence','wife_parents','wedding_date','wedding_location','sponsors','witnesses_residence','officiating_priest','remarks','parish_priest','parish_secretary'],
        ],
        'funeral' => [
            'table' => 'funeral_records', 'id' => 'funeral_id',
            'name' => ['deceased_name'], 'birth' => ['birth_date'], 'event' => 'date_of_burial',
            'required' => ['deceased_name','birth_date','date_of_death','date_of_burial','place_of_burial','minister'],
            'request_types' => ['funeral_mass'],
            'fields' => ['request_id','registry_no','book_no','page_no','entry_no','deceased_name','birth_date','family_name','date_of_death','date_of_burial','civil_status','funeral_rites','cause_of_death','place_of_burial','minister','remarks'],
        ],
    ];

    public function __construct(mysqli $db) { $this->db = $db; }
    public static function types(): array { return array_keys(self::TYPES); }
    public static function definition(string $type): array
    {
        if (!isset(self::TYPES[$type])) throw new InvalidArgumentException('Unsupported sacramental record type.');
        return self::TYPES[$type];
    }

    public function validate(string $type, array $input, ?int $excludeId = null): array
    {
        $cfg = self::definition($type);
        // Preserve the established form field while storing the canonical column.
        if ($type === 'confirmation' && empty($input['bishop_priest']) && !empty($input['minister'])) $input['bishop_priest'] = $input['minister'];
        $data = [];
        foreach ($cfg['fields'] as $field) {
            $value = $input[$field] ?? null;
            $data[$field] = is_string($value) ? trim($value) : $value;
            if ($data[$field] === '') $data[$field] = null;
        }
        $data['request_id'] = empty($data['request_id']) ? null : (int)$data['request_id'];
        $errors = [];
        foreach ($cfg['required'] as $field) if (empty($data[$field])) $errors[] = $this->label($field) . ' is required.';
        $hasRegistry = !empty($data['registry_no']);
        $registryParts = array_filter([$data['book_no'], $data['page_no'], $data['entry_no']], static fn($v) => $v !== null);
        if (!$hasRegistry && count($registryParts) !== 3) $errors[] = 'Provide a registry number or the complete book, page, and entry numbers.';
        if (count($registryParts) > 0 && count($registryParts) < 3) $errors[] = 'Book, page, and entry numbers must be supplied together.';

        $dateFields = array_values(array_unique(array_merge($cfg['birth'], [$cfg['event']], $type === 'funeral' ? ['date_of_death'] : [], $type === 'communion' ? ['baptismal_date'] : [])));
        foreach ($dateFields as $field) {
            if (!empty($data[$field]) && !$this->realDate((string)$data[$field])) $errors[] = $this->label($field) . ' must be a real date in YYYY-MM-DD format.';
        }
        $today = date('Y-m-d');
        foreach ($cfg['birth'] as $field) if (!empty($data[$field]) && $data[$field] > $today) $errors[] = $this->label($field) . ' cannot be in the future.';
        if ($type !== 'marriage' && !empty($data[$cfg['birth'][0]]) && !empty($data[$cfg['event']]) && $data[$cfg['event']] < $data[$cfg['birth'][0]]) $errors[] = 'The sacramental event cannot occur before birth.';
        if ($type === 'marriage' && !empty($data['wedding_date'])) {
            foreach (['husband_birth_date','wife_birth_date'] as $field) if (!empty($data[$field]) && $data['wedding_date'] < $data[$field]) $errors[] = 'Marriage cannot occur before either spouse was born.';
        }
        if ($type === 'funeral' && !empty($data['birth_date']) && !empty($data['date_of_death']) && $data['date_of_death'] < $data['birth_date']) $errors[] = 'Death cannot occur before birth.';
        if ($type === 'funeral' && !empty($data['date_of_death']) && !empty($data['date_of_burial']) && $data['date_of_burial'] < $data['date_of_death']) $errors[] = 'Burial cannot occur before death.';
        if ($type === 'communion' && !empty($data['baptismal_date']) && !empty($data['communion_date']) && $data['communion_date'] < $data['baptismal_date']) $errors[] = 'First Communion cannot occur before baptism.';
        if ($data['request_id']) $this->validateRequestLink($type, $data['request_id'], $excludeId, $errors);
        $data['duplicate_fingerprint'] = $this->fingerprint($cfg, $data);
        $duplicate = $this->findDuplicate($cfg, $data, $excludeId);
        if ($duplicate) $errors[] = 'An exact or official-registry duplicate already exists (record #' . (int)$duplicate . ').';
        return ['valid' => !$errors, 'errors' => $errors, 'data' => $data];
    }

    public function create(string $type, array $input, int $actorId, bool $manageTransaction = true): int
    {
        $cfg = self::definition($type); $check = $this->validate($type, $input);
        if (!$check['valid']) throw new DomainException(implode(' ', $check['errors']));
        $data = $check['data']; $fields = array_merge($cfg['fields'], ['duplicate_fingerprint']);
        $values = array_map(static fn($f) => $data[$f] ?? null, $fields);
        $sql = 'INSERT INTO `' . $cfg['table'] . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')';
        if ($manageTransaction) $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare($sql); $this->bind($stmt, $values); $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close();
            $this->audit($actorId, 'CREATE_SACRAMENTAL_RECORD', $cfg['table'], $id, null, $data);
            if ($manageTransaction) $this->db->commit(); return $id;
        } catch (Throwable $e) { if ($manageTransaction) $this->db->rollback(); if ((int)$this->db->errno === 1062) throw new DomainException('That registry number or book/page/entry combination already exists.'); throw $e; }
    }

    public function requestCorrection(string $type, int $recordId, array $input, string $reason, int $actorId): int
    {
        $cfg = self::definition($type); if (trim($reason) === '') throw new DomainException('A correction reason is required.');
        $old = $this->record($cfg, $recordId, true); $check = $this->validate($type, $input, $recordId);
        if (!$check['valid']) throw new DomainException(implode(' ', $check['errors']));
        $changes = [];
        foreach ($cfg['fields'] as $field) if ((string)($old[$field] ?? '') !== (string)($check['data'][$field] ?? '')) $changes[$field] = [$old[$field] ?? null, $check['data'][$field] ?? null];
        if (!$changes) throw new DomainException('No record changes were supplied.');
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO sacramental_record_corrections(record_type,record_id,reason,requested_by) VALUES(?,?,?,?)");
            $stmt->bind_param('sisi', $type, $recordId, $reason, $actorId); $stmt->execute(); $cid = (int)$stmt->insert_id; $stmt->close();
            $stmt = $this->db->prepare('INSERT INTO sacramental_correction_changes(correction_id,field_name,previous_value,new_value) VALUES(?,?,?,?)');
            foreach ($changes as $field => $pair) { [$before,$after] = $pair; $stmt->bind_param('isss', $cid, $field, $before, $after); $stmt->execute(); } $stmt->close();
            $this->audit($actorId, 'REQUEST_RECORD_CORRECTION', $cfg['table'], $recordId, $old, ['correction_id'=>$cid,'reason'=>$reason]);
            $this->db->commit(); return $cid;
        } catch (Throwable $e) { $this->db->rollback(); throw $e; }
    }

    public function reviewCorrection(int $correctionId, bool $approve, string $reason, int $actorId, bool $canCorrectLocked): void
    {
        $this->db->begin_transaction();
        try {
            $stmt=$this->db->prepare('SELECT * FROM sacramental_record_corrections WHERE correction_id=? FOR UPDATE'); $stmt->bind_param('i',$correctionId); $stmt->execute(); $correction=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$correction || $correction['status'] !== 'pending') throw new DomainException('This correction is no longer pending.');
            $cfg=self::definition($correction['record_type']); $record=$this->record($cfg,(int)$correction['record_id'],true);
            if (!$approve) { $stmt=$this->db->prepare("UPDATE sacramental_record_corrections SET status='rejected',approved_by=?,rejected_at=NOW(),review_reason=? WHERE correction_id=?"); $stmt->bind_param('isi',$actorId,$reason,$correctionId); $stmt->execute(); $stmt->close(); $this->db->commit(); return; }
            if (!empty($record['locked_at']) && !$canCorrectLocked) throw new DomainException('Locked records require the records.correct_locked permission.');
            $stmt=$this->db->prepare('SELECT field_name,new_value FROM sacramental_correction_changes WHERE correction_id=?'); $stmt->bind_param('i',$correctionId); $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
            $candidate=$record; foreach($rows as $row) $candidate[$row['field_name']]=$row['new_value']; $check=$this->validate($correction['record_type'],$candidate,(int)$correction['record_id']);
            if(!$check['valid']) throw new DomainException(implode(' ',$check['errors']));
            $sets=[];$values=[]; foreach($rows as $row){$sets[]='`'.$row['field_name'].'`=?';$values[]=$row['new_value'];} $sets[]='duplicate_fingerprint=?';$values[]=$check['data']['duplicate_fingerprint'];$values[]=(int)$correction['record_id'];
            $stmt=$this->db->prepare('UPDATE `'.$cfg['table'].'` SET '.implode(',',$sets).' WHERE `'.$cfg['id'].'`=?');$this->bind($stmt,$values);$stmt->execute();$stmt->close();
            $stmt=$this->db->prepare("UPDATE sacramental_record_corrections SET status='applied',approved_by=?,edited_by=?,approved_at=NOW(),applied_at=NOW(),review_reason=? WHERE correction_id=?");$stmt->bind_param('iisi',$actorId,$actorId,$reason,$correctionId);$stmt->execute();$stmt->close();
            $this->audit($actorId,'APPLY_RECORD_CORRECTION',$cfg['table'],(int)$correction['record_id'],$record,['correction_id'=>$correctionId,'changes'=>$rows]);
            $this->db->commit();
        } catch(Throwable $e){$this->db->rollback();throw $e;}
    }

    public function archive(string $type,int $recordId,string $reason,int $actorId):void { $this->setArchive($type,$recordId,true,$reason,$actorId); }
    public function restore(string $type,int $recordId,int $actorId):void { $this->setArchive($type,$recordId,false,'',$actorId); }
    public function lock(string $type,int $recordId,int $actorId,string $reason):void
    { $cfg=self::definition($type);$stmt=$this->db->prepare('UPDATE `'.$cfg['table'].'` SET locked_at=COALESCE(locked_at,NOW()),locked_by=COALESCE(locked_by,?),lock_reason=COALESCE(lock_reason,?) WHERE `'.$cfg['id'].'`=?');$stmt->bind_param('isi',$actorId,$reason,$recordId);$stmt->execute();$stmt->close(); }

    private function setArchive(string $type,int $id,bool $archive,string $reason,int $actor):void
    { $cfg=self::definition($type);if($archive&&trim($reason)==='')throw new DomainException('An archive reason is required.');$sql=$archive?"UPDATE `{$cfg['table']}` SET status='archived',archived_at=NOW(),archived_by=?,archive_reason=? WHERE `{$cfg['id']}`=?":"UPDATE `{$cfg['table']}` SET status='active',restored_at=NOW(),restored_by=? WHERE `{$cfg['id']}`=?";$stmt=$this->db->prepare($sql);if($archive)$stmt->bind_param('isi',$actor,$reason,$id);else$stmt->bind_param('ii',$actor,$id);$stmt->execute();if($stmt->affected_rows!==1)throw new DomainException('Record not found.');$stmt->close();$this->audit($actor,$archive?'ARCHIVE_SACRAMENTAL_RECORD':'RESTORE_SACRAMENTAL_RECORD',$cfg['table'],$id,null,['reason'=>$reason]); }
    private function record(array $cfg,int $id,bool $lock):array { $stmt=$this->db->prepare('SELECT * FROM `'.$cfg['table'].'` WHERE `'.$cfg['id'].'`=?'.($lock?' FOR UPDATE':''));$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$row)throw new DomainException('Record not found.');return $row; }
    private function validateRequestLink(string $type,int $requestId,?int $excludeId,array &$errors):void
    { $cfg=self::definition($type);$stmt=$this->db->prepare('SELECT request_type,status,deleted_at FROM requests WHERE request_id=?');$stmt->bind_param('i',$requestId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$r||$r['deleted_at']!==null){$errors[]='The linked request does not exist.';return;}if(!in_array($r['request_type'],$cfg['request_types'],true))$errors[]='The linked request type is not compatible with this record.';if(!in_array($r['status'],['approved','scheduled','processing','ready_for_release','completed'],true))$errors[]='Only an approved or later-stage request may be linked.';$stmt=$this->db->prepare('SELECT `'.$cfg['id'].'` id FROM `'.$cfg['table'].'` WHERE request_id=?'.($excludeId?' AND `'.$cfg['id'].'`<>?':''));if($excludeId)$stmt->bind_param('ii',$requestId,$excludeId);else$stmt->bind_param('i',$requestId);$stmt->execute();if($stmt->get_result()->fetch_assoc())$errors[]='That request is already linked to another record.';$stmt->close(); }
    private function findDuplicate(array $cfg,array $data,?int $exclude):?int
    { $parts=[];$values=[];if($data['registry_no']){$parts[]='registry_no=?';$values[]=$data['registry_no'];}if($data['book_no']&&$data['page_no']&&$data['entry_no']){$parts[]='(book_no=? AND page_no=? AND entry_no=?)';array_push($values,$data['book_no'],$data['page_no'],$data['entry_no']);}$parts[]='duplicate_fingerprint=?';$values[]=$data['duplicate_fingerprint'];$sql='SELECT `'.$cfg['id'].'` FROM `'.$cfg['table'].'` WHERE ('.implode(' OR ',$parts).')'.($exclude?' AND `'.$cfg['id'].'`<>?':'').' LIMIT 1';if($exclude)$values[]=$exclude;$stmt=$this->db->prepare($sql);$this->bind($stmt,$values);$stmt->execute();$row=$stmt->get_result()->fetch_row();$stmt->close();return $row?(int)$row[0]:null; }
    private function fingerprint(array $cfg,array $data):string { $values=[];foreach(array_merge($cfg['name'],$cfg['birth'],[$cfg['event']])as$f)$values[]=$this->normalize($data[$f]??'');if(isset($data['parents']))$values[]=$this->normalize($data['parents']);if(isset($data['husband_parents']))$values[]=$this->normalize($data['husband_parents']);if(isset($data['wife_parents']))$values[]=$this->normalize($data['wife_parents']);return hash('sha256',implode('|',$values)); }
    private function normalize($v):string { $v=mb_strtolower(trim((string)$v),'UTF-8');return preg_replace('/[^\pL\pN]+/u','',$v)??''; }
    private function realDate(string $v):bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v; }
    private function label(string $f):string { return ucfirst(str_replace('_',' ',$f)); }
    private function bind(mysqli_stmt $stmt,array &$values):void { $types='';foreach($values as$v)$types.=is_int($v)?'i':'s';$refs=[$types];foreach($values as$i=>&$v)$refs[]=&$v;call_user_func_array([$stmt,'bind_param'],$refs); }
    private function audit(int $actor,string $action,string $table,int $id,$old,$new):void { if(function_exists('createAuditLog'))createAuditLog($this->db,$actor,$action,$table,$id,$old,$new); }
}
