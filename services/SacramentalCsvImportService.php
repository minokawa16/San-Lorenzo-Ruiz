<?php
require_once __DIR__ . '/SacramentalRecordService.php';

final class SacramentalCsvImportService
{
    private mysqli $db; private SacramentalRecordService $records; private string $root;
    public function __construct(mysqli $db,string $root){$this->db=$db;$this->records=new SacramentalRecordService($db);$this->root=rtrim($root,'/\\');}

    public function stage(string $type,array $file,int $actor):int
    {
        SacramentalRecordService::definition($type);
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new DomainException('Choose a CSV file to upload.');
        if((int)($file['size']??0)<=0||(int)$file['size']>2097152)throw new DomainException('CSV must be between 1 byte and 2 MB.');
        $original=basename((string)($file['name']??'import.csv'));if(strtolower(pathinfo($original,PATHINFO_EXTENSION))!=='csv')throw new DomainException('Only .csv files are accepted.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!in_array($mime,['text/plain','text/csv','application/csv','application/vnd.ms-excel'],true))throw new DomainException('The uploaded file is not a valid CSV.');
        $bytes=file_get_contents($file['tmp_name']);if($bytes===false||strpos($bytes,"\0")!==false||!mb_check_encoding($bytes,'UTF-8'))throw new DomainException('CSV must be UTF-8 text without binary content.');
        $hash=hash('sha256',$bytes);$dir=$this->root.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'sacramental_imports';if(!is_dir($dir)&&!mkdir($dir,0750,true))throw new RuntimeException('Unable to create protected import storage.');
        $stored='uploads/sacramental_imports/'.bin2hex(random_bytes(16)).'.csv';$absolute=$this->root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$stored);if(!move_uploaded_file($file['tmp_name'],$absolute))throw new RuntimeException('Unable to store the CSV securely.');
        $stmt=$this->db->prepare("INSERT INTO sacramental_import_batches(record_type,original_name,stored_path,file_hash,created_by) VALUES(?,?,?,?,?)");$stmt->bind_param('ssssi',$type,$original,$stored,$hash,$actor);
        try{$stmt->execute();$id=(int)$stmt->insert_id;$stmt->close();}catch(Throwable $e){@unlink($absolute);if((int)$this->db->errno===1062)throw new DomainException('This exact CSV was already staged by you.');throw $e;}
        $this->parseAndValidate($id,$absolute,$type);return $id;
    }

    private function parseAndValidate(int $id,string $path,string $type):void
    {
        $h=fopen($path,'rb');$headers=fgetcsv($h);if(!$headers){fclose($h);throw new DomainException('CSV has no header row.');}$headers=array_map(fn($v)=>strtolower(trim((string)$v)),$headers);if(count($headers)!==count(array_unique($headers)))throw new DomainException('CSV headers must be unique.');
        $allowed=SacramentalRecordService::definition($type)['fields'];$unknown=array_diff($headers,$allowed);if($unknown)throw new DomainException('Unknown CSV columns: '.implode(', ',$unknown));
        $insert=$this->db->prepare('INSERT INTO sacramental_import_rows(import_id,row_number,row_data,validation_status,errors_json) VALUES(?,?,?,?,?)');$rowNo=1;$total=$valid=$invalid=0;
        while(($cells=fgetcsv($h))!==false){$rowNo++;if(count($cells)!==count($headers)){$data=[];$errors=['Column count does not match the header.'];}else{$data=array_combine($headers,$cells);$errors=[];foreach($data as$field=>$value)if(preg_match('/^[=+\-@]/',ltrim((string)$value)))$errors[]=$field.' begins with a spreadsheet formula character.';$check=$this->records->validate($type,$data);$errors=array_merge($errors,$check['errors']);}
            $status=$errors?'invalid':'valid';$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$err=json_encode($errors,JSON_UNESCAPED_UNICODE);$insert->bind_param('iisss',$id,$rowNo,$json,$status,$err);$insert->execute();$total++;$errors?$invalid++:$valid++;if($total>5000){fclose($h);throw new DomainException('CSV exceeds the 5,000 row limit.');}}
        fclose($h);$insert->close();$stmt=$this->db->prepare('UPDATE sacramental_import_batches SET total_rows=?,valid_rows=?,invalid_rows=? WHERE import_id=?');$stmt->bind_param('iiii',$total,$valid,$invalid,$id);$stmt->execute();$stmt->close();
    }

    public function confirm(int $id,int $actor):int
    {
        $this->db->begin_transaction();try{$stmt=$this->db->prepare('SELECT * FROM sacramental_import_batches WHERE import_id=? FOR UPDATE');$stmt->bind_param('i',$id);$stmt->execute();$batch=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$batch||$batch['status']!=='preview')throw new DomainException('Import is missing or already confirmed.');if((int)$batch['invalid_rows']>0)throw new DomainException('Fix every invalid row before confirming this import.');$stmt=$this->db->prepare("SELECT import_row_id,row_data FROM sacramental_import_rows WHERE import_id=? AND validation_status='valid' ORDER BY row_number");$stmt->bind_param('i',$id);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$count=0;foreach($rows as$row){$recordId=$this->records->create($batch['record_type'],json_decode($row['row_data'],true),(int)$actor,false);$u=$this->db->prepare('UPDATE sacramental_import_rows SET imported_record_id=? WHERE import_row_id=?');$u->bind_param('ii',$recordId,$row['import_row_id']);$u->execute();$u->close();$count++;}$stmt=$this->db->prepare("UPDATE sacramental_import_batches SET status='imported',confirmed_by=?,confirmed_at=NOW(),imported_at=NOW() WHERE import_id=?");$stmt->bind_param('ii',$actor,$id);$stmt->execute();$stmt->close();$this->db->commit();return$count;}catch(Throwable$e){$this->db->rollback();throw$e;}
    }
    public function batch(int$id,int$actor):array{$stmt=$this->db->prepare('SELECT * FROM sacramental_import_batches WHERE import_id=? AND created_by=?');$stmt->bind_param('ii',$id,$actor);$stmt->execute();$b=$stmt->get_result()->fetch_assoc();$stmt->close();if(!$b)throw new DomainException('Import not found.');$stmt=$this->db->prepare('SELECT * FROM sacramental_import_rows WHERE import_id=? ORDER BY row_number LIMIT 250');$stmt->bind_param('i',$id);$stmt->execute();$b['rows']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return$b;}
}
