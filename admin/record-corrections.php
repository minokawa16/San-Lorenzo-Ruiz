<?php
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';
require_once '../services/SacramentalRecordService.php';
requireAdmin();
requirePermission('records.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        $action=(string)($_POST['action']??'');
        if(!in_array($action,['approve','reject'],true)) throw new DomainException('Invalid correction action.');
        (new SacramentalRecordService($conn))->reviewCorrection((int)($_POST['correction_id']??0),$action==='approve',(string)($_POST['review_reason']??''),(int)$_SESSION['user_id'],hasPermission('records.correct_locked'));
        redirectWithNotification('record-corrections.php',$action==='approve'?'Correction approved and applied.':'Correction rejected.','success');
    } catch(Throwable $e){redirectWithNotification('record-corrections.php',$e->getMessage(),'error');}
}
$requested_status=(string)($_GET['status']??'pending');$status=in_array($requested_status,['pending','applied','rejected'],true)?$requested_status:'pending';
$stmt=$conn->prepare("SELECT c.*,u.fullname requester,COUNT(ch.change_id) change_count FROM sacramental_record_corrections c JOIN users u ON u.id=c.requested_by LEFT JOIN sacramental_correction_changes ch ON ch.correction_id=c.correction_id WHERE c.status=? GROUP BY c.correction_id ORDER BY c.requested_at DESC");
$stmt->bind_param('s',$status);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
$changes=[];if(isset($_GET['view'])){$id=(int)$_GET['view'];$stmt=$conn->prepare('SELECT field_name,previous_value,new_value FROM sacramental_correction_changes WHERE correction_id=? ORDER BY change_id');$stmt->bind_param('i',$id);$stmt->execute();$changes=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();}
$page_title='Record Corrections';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo e($page_title); ?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../assets/css/holy-theme.css"></head><body>
<?php include '../includes/admin-sidebar.php'; ?>
<main class="admin-content p-4" style="margin-left:280px"><div class="d-flex justify-content-between align-items-center mb-4"><div><h1>Record correction review</h1><p class="text-muted mb-0">Official values change only after this review.</p></div><a class="btn btn-outline-secondary" href="manage-records.php">Records</a></div>
<nav class="nav nav-pills mb-3"><?php foreach(['pending','applied','rejected'] as $s):?><a class="nav-link <?php echo $status===$s?'active':'';?>" href="?status=<?php echo e($s);?>"><?php echo e(ucfirst($s));?></a><?php endforeach;?></nav>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID</th><th>Record</th><th>Reason</th><th>Requester</th><th>Changes</th><th></th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6" class="text-center p-4 text-muted">No <?php echo e($status);?> corrections.</td></tr><?php endif;?><?php foreach($rows as$r):?><tr><td>#<?php echo(int)$r['correction_id'];?></td><td><?php echo e(ucfirst($r['record_type']));?> #<?php echo(int)$r['record_id'];?></td><td><?php echo e($r['reason']);?></td><td><?php echo e($r['requester']);?></td><td><?php echo(int)$r['change_count'];?></td><td><a class="btn btn-sm btn-outline-primary" href="?status=<?php echo e($status);?>&view=<?php echo(int)$r['correction_id'];?>">Review</a></td></tr><?php endforeach;?></tbody></table></div></div>
<?php if($changes):?><div class="card mt-4"><div class="card-header fw-bold">Proposed field changes</div><div class="table-responsive"><table class="table"><thead><tr><th>Field</th><th>Current</th><th>Proposed</th></tr></thead><tbody><?php foreach($changes as$c):?><tr><td><?php echo e(str_replace('_',' ',$c['field_name']));?></td><td><?php echo e($c['previous_value']);?></td><td><?php echo e($c['new_value']);?></td></tr><?php endforeach;?></tbody></table></div><?php if($status==='pending'):?><form method="post" class="card-body border-top"><?php echo csrfInput();?><input type="hidden" name="correction_id" value="<?php echo(int)$_GET['view'];?>"><label class="form-label">Review note</label><textarea class="form-control mb-3" name="review_reason" maxlength="1000"></textarea><button class="btn btn-success" name="action" value="approve">Approve and apply</button> <button class="btn btn-danger" name="action" value="reject">Reject</button></form><?php endif;?></div><?php endif;?>
</main><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script></body></html>
