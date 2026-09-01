<?php
/**
 * Chatbot Knowledge Base
 * Admin-managed official information used by TUGON AI Parish Assistant.
 */

include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireLogin();
requirePermission('ai.manage.knowledge');

$page_title = 'Chatbot Knowledge Base';
$error = '';
$success = '';

$categories = [
    'sacrament' => 'Sacrament',
    'certificate' => 'Certificate',
    'schedule' => 'Schedule',
    'announcement' => 'Announcement',
    'blessing' => 'Blessing',
    'office' => 'Parish Office',
    'payment' => 'Payment',
    'reservation' => 'Reservation',
    'organization' => 'Organization',
    'policy' => 'Policy / Guideline',
    'general' => 'General'
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requireValidCsrfToken();
    $action = $_POST['action'] ?? '';
    $knowledge_id = intval($_POST['knowledge_id'] ?? 0);

    if ($action === 'save') {
        $topic = trim((string) ($_POST['topic'] ?? ''));
        $keywords = trim((string) ($_POST['keywords'] ?? ''));
        $answer = trim((string) ($_POST['answer'] ?? ''));
        $steps = trim((string) ($_POST['steps'] ?? ''));
        $source = trim((string) ($_POST['source'] ?? ''));
        $category = $_POST['category'] ?? 'general';
        $status = $_POST['status'] ?? 'active';
        $approval_status = $_POST['approval_status'] ?? 'draft';
        $effective_date = trim((string)($_POST['effective_date'] ?? '')) ?: null;
        $expiry_date = trim((string)($_POST['expiry_date'] ?? '')) ?: null;
        $language = $_POST['language'] ?? 'bilingual';

        if ($topic === '' || $answer === '') {
            $error = 'Topic and official answer are required.';
        } elseif (!isset($categories[$category])) {
            $error = 'Please choose a valid category.';
        } elseif (!in_array($status, ['active', 'inactive'], true)) {
            $error = 'Please choose a valid status.';
        } elseif (!in_array($approval_status, ['draft','approved','rejected','superseded'], true) || !in_array($language, ['en','fil','bilingual'], true)) {
            $error = 'Please choose valid governance metadata.';
        } elseif ($approval_status === 'approved' && ($source === '' || $effective_date === null)) {
            $error = 'Approved knowledge requires a verified source and effective date.';
        } elseif ($expiry_date !== null && $effective_date !== null && $expiry_date < $effective_date) {
            $error = 'Expiry date cannot be before the effective date.';
        } else {
            $actor = intval($_SESSION['user_id'] ?? 0);
            $reviewer = $approval_status === 'approved' ? $actor : null;
            $content_hash = hash('sha256', implode('|', [$topic,$keywords,$answer,$steps]));
            if ($knowledge_id > 0) {
                $stmt = $conn->prepare("UPDATE chatbot_knowledge SET topic=?,keywords=?,answer=?,steps=?,category=?,source=?,status=?,approval_status=?,author_id=COALESCE(author_id,?),reviewer_id=?,version=version+1,effective_date=?,expiry_date=?,language=?,reviewed_at=IF(?='approved',NOW(),NULL),content_hash=?,updated_by=? WHERE knowledge_id=?");
                $stmt->bind_param('ssssssssiisssssii', $topic,$keywords,$answer,$steps,$category,$source,$status,$approval_status,$actor,$reviewer,$effective_date,$expiry_date,$language,$approval_status,$content_hash,$actor,$knowledge_id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) {
                    createAuditLog($conn, $_SESSION['user_id'], 'UPDATE_CHATBOT_KNOWLEDGE', 'chatbot_knowledge', $knowledge_id);
                    $success = 'Chatbot knowledge updated successfully.';
                } else {
                    $error = 'Unable to update chatbot knowledge.';
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO chatbot_knowledge(topic,keywords,answer,steps,category,source,status,approval_status,author_id,reviewer_id,effective_date,expiry_date,language,reviewed_at,content_hash,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?='approved',NOW(),NULL),?,?)");
                $stmt->bind_param('ssssssssiisssssi', $topic,$keywords,$answer,$steps,$category,$source,$status,$approval_status,$actor,$reviewer,$effective_date,$expiry_date,$language,$approval_status,$content_hash,$actor);
                $ok = $stmt->execute();
                $new_id = $stmt->insert_id;
                $stmt->close();
                if ($ok) {
                    createAuditLog($conn, $_SESSION['user_id'], 'CREATE_CHATBOT_KNOWLEDGE', 'chatbot_knowledge', $new_id);
                    $success = 'Chatbot knowledge added successfully.';
                } else {
                    $error = 'Unable to add chatbot knowledge.';
                }
            }
        }
    } elseif ($action === 'toggle' && $knowledge_id > 0) {
        $stmt = $conn->prepare("UPDATE chatbot_knowledge SET status=IF(status='active','inactive','active'),approval_status=IF(status='active','superseded','draft'),version=version+1,updated_by=? WHERE knowledge_id=?");
        $updated_by = intval($_SESSION['user_id'] ?? 0);
        $stmt->bind_param('ii', $updated_by, $knowledge_id);
        if ($stmt->execute()) {
            createAuditLog($conn, $_SESSION['user_id'], 'TOGGLE_CHATBOT_KNOWLEDGE', 'chatbot_knowledge', $knowledge_id);
            $success = 'Chatbot knowledge status updated.';
        } else {
            $error = 'Unable to update status.';
        }
        $stmt->close();
    } elseif ($action === 'delete' && $knowledge_id > 0) {
        $actor=intval($_SESSION['user_id']??0);
        $stmt = $conn->prepare("UPDATE chatbot_knowledge SET status='inactive',approval_status='superseded',version=version+1,updated_by=? WHERE knowledge_id=?");
        $stmt->bind_param('ii',$actor,$knowledge_id);
        if ($stmt->execute()) {
            createAuditLog($conn, $_SESSION['user_id'], 'DELETE_CHATBOT_KNOWLEDGE', 'chatbot_knowledge', $knowledge_id);
            $success = 'Chatbot knowledge was safely superseded.';
        } else {
            $error = 'Unable to delete chatbot knowledge.';
        }
        $stmt->close();
    }
}

$edit_id = intval($_GET['edit'] ?? 0);
$edit_item = null;
if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM chatbot_knowledge WHERE knowledge_id = ? LIMIT 1");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_item = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$search = trim((string) ($_GET['q'] ?? ''));
$where = '';
$params = [];
$types = '';
if ($search !== '') {
    $where = "WHERE topic LIKE ? OR keywords LIKE ? OR answer LIKE ? OR source LIKE ?";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}
$countSql="SELECT COUNT(*) count FROM chatbot_knowledge $where";
if($params){$countStmt=$conn->prepare($countSql);$countStmt->bind_param($types,...$params);$countStmt->execute();$total_items=(int)($countStmt->get_result()->fetch_assoc()['count']??0);$countStmt->close();}else{$total_items=(int)($conn->query($countSql)->fetch_assoc()['count']??0);}

$page=max(1,intval($_GET['page']??1)); $per_page=25; $offset=($page-1)*$per_page; $items = [];
$total_pages=max(1,(int)ceil($total_items/$per_page));
$sql = "SELECT ck.*, u.fullname AS updated_by_name
        FROM chatbot_knowledge ck
        LEFT JOIN users u ON u.id = ck.updated_by
        $where
        ORDER BY ck.status = 'active' DESC, ck.updated_at DESC, ck.topic ASC LIMIT $per_page OFFSET $offset";
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();
} else {
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) {
        $items[] = $row;
    }
}

$breadcrumbs = [
    'Dashboard' => 'dashboard.php',
    'Settings' => 'settings.php',
    'Chatbot Knowledge Base' => null
];

include '../templates/header.php';
?>

<style>
    .kb-grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 18px; align-items: start; }
    .kb-card { background: #fff; border: 1px solid rgba(28, 27, 24, 0.08); border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03); }
    .kb-card-header { padding: 14px 18px; border-bottom: 1px solid rgba(28, 27, 24, 0.08); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .kb-card-body { padding: 18px; }
    .kb-answer { max-width: 420px; color: #475569; font-size: .9rem; }
    .kb-keywords { color: #64748b; font-size: .78rem; }
    @media (max-width: 992px) { .kb-grid { grid-template-columns: 1fr; } }
</style>

<div class="container-fluid px-0">
    <!-- Standardized Section Header -->
    <?php
    $page_header_title = 'Chatbot Knowledge Base';
    $page_header_subtitle = 'Add and manage official parish information used by TUGON AI Parish Assistant.';
    $page_header_icon = 'fa-brain';
    $show_back_button = true;
    $back_button_url = 'settings.php';
    include '../includes/page_header.php';
    ?>

    <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?php echo e($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?php echo e($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <section class="kb-grid">
                <form class="kb-card" method="POST">
                    <?php echo csrfInput(); ?>
                    <div class="kb-card-header">
                        <h2 class="h5 mb-0"><i class="fas fa-pen-to-square"></i> <?php echo $edit_item ? 'Edit Knowledge' : 'Add Knowledge'; ?></h2>
                        <?php if ($edit_item): ?><a href="chatbot-knowledge.php" class="btn btn-sm btn-outline-secondary">New</a><?php endif; ?>
                    </div>
                    <div class="kb-card-body">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="knowledge_id" value="<?php echo intval($edit_item['knowledge_id'] ?? 0); ?>">

                        <div class="mb-3">
                            <label class="form-label" for="kbTopic">Topic <span class="text-danger">*</span></label>
                            <input class="form-control" id="kbTopic" name="topic" required value="<?php echo e($edit_item['topic'] ?? ''); ?>" placeholder="Example: Baptism Requirements">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Keywords / Phrases</label>
                            <textarea class="form-control" name="keywords" rows="3" placeholder="baptism, binyag, pabinyag, papers for baptism"><?php echo e($edit_item['keywords'] ?? ''); ?></textarea>
                            <div class="form-text">Add English, Filipino, Tagalog, Taglish, abbreviations, and common misspellings.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Official Answer <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="answer" rows="5" required placeholder="Official parish answer only. Do not put guesses here."><?php echo e($edit_item['answer'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Numbered Steps / Requirements</label>
                            <textarea class="form-control" name="steps" rows="7" placeholder="One item per line"><?php echo e($edit_item['steps'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="kbSource">Verified Source</label>
                            <input class="form-control" id="kbSource" name="source" maxlength="255" value="<?php echo e($edit_item['source'] ?? ''); ?>" placeholder="Example: Parish office memorandum, 2026">
                            <div class="form-text">Identify where staff verified this information.</div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="kbApproval">Approval</label>
                                <select class="form-select" id="kbApproval" name="approval_status">
                                    <?php foreach (['draft'=>'Draft','approved'=>'Approved','rejected'=>'Rejected','superseded'=>'Superseded'] as $key=>$label): ?>
                                    <option value="<?php echo e($key); ?>" <?php echo (($edit_item['approval_status']??'draft')===$key)?'selected':''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="kbLanguage">Language</label>
                                <select class="form-select" id="kbLanguage" name="language">
                                    <option value="bilingual" <?php echo (($edit_item['language']??'bilingual')==='bilingual')?'selected':''; ?>>English + Tagalog</option>
                                    <option value="en" <?php echo (($edit_item['language']??'')==='en')?'selected':''; ?>>English</option>
                                    <option value="fil" <?php echo (($edit_item['language']??'')==='fil')?'selected':''; ?>>Tagalog</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="kbEffective">Effective date</label>
                                <input class="form-control" id="kbEffective" type="date" name="effective_date" value="<?php echo e($edit_item['effective_date']??date('Y-m-d')); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="kbExpiry">Expiry date</label>
                                <input class="form-control" id="kbExpiry" type="date" name="expiry_date" value="<?php echo e($edit_item['expiry_date']??''); ?>">
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <?php foreach ($categories as $key => $label): ?>
                                        <option value="<?php echo e($key); ?>" <?php echo (($edit_item['category'] ?? 'general') === $key) ? 'selected' : ''; ?>><?php echo e($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="active" <?php echo (($edit_item['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo (($edit_item['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                        <button class="btn btn-primary w-100" type="submit"><i class="fas fa-save"></i> Save Knowledge</button>
                    </div>
                </form>

                <div class="kb-card">
                    <div class="kb-card-header">
                        <h2 class="h5 mb-0"><i class="fas fa-database"></i> Official AI Information</h2>
                        <form class="d-flex gap-2" method="GET">
                            <input class="form-control form-control-sm" name="q" value="<?php echo e($search); ?>" placeholder="Search">
                            <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
                        </form>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover kb-table mb-0">
                            <thead>
                                <tr>
                                    <th>Topic</th>
                                    <th>Official Answer</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$items): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-4">No chatbot knowledge found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo e($item['topic']); ?></strong>
                                            <div class="kb-keywords"><?php echo e($item['keywords'] ?: 'No keywords'); ?></div>
                                            <small class="text-muted">Updated by <?php echo e($item['updated_by_name'] ?: 'System'); ?></small>
                                            <?php if (!empty($item['source'])): ?><small class="d-block text-muted">Source: <?php echo e($item['source']); ?></small><?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="kb-answer"><?php echo e(mb_strimwidth($item['answer'], 0, 220, '...')); ?></div>
                                            <?php $step_count = count(chatbotKnowledgeStepsArray($item['steps'] ?? '')); ?>
                                            <?php if ($step_count): ?><small class="text-muted"><?php echo $step_count; ?> numbered item(s)</small><?php endif; ?>
                                        </td>
                                        <td><span class="badge <?php echo $item['status'] === 'active' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo e(ucfirst($item['status'])); ?></span></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="chatbot-knowledge.php?edit=<?php echo intval($item['knowledge_id']); ?>"><i class="fas fa-pen"></i></a>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="knowledge_id" value="<?php echo intval($item['knowledge_id']); ?>">
                                                <button class="btn btn-sm btn-outline-warning" type="submit"><i class="fas fa-power-off"></i></button>
                                            </form>
                                            <form method="POST" class="d-inline" data-confirm="Supersede this knowledge entry? It will remain in history and stop appearing in official AI answers.">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="knowledge_id" value="<?php echo intval($item['knowledge_id']); ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Supersede <?php echo e($item['topic']); ?>"><i class="fas fa-box-archive" aria-hidden="true"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if($total_pages>1): ?><nav class="p-3" aria-label="Knowledge pages"><ul class="pagination mb-0"><?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?><li class="page-item <?php echo $p===$page?'active':''; ?>"><a class="page-link" href="?<?php echo e(http_build_query(array_filter(['q'=>$search,'page'=>$p]))); ?>"><?php echo $p; ?></a></li><?php endfor; ?></ul></nav><?php endif; ?>
                </div>
</div>
<?php include '../templates/footer.php'; ?>
