<?php
include '../includes/session.php';
include '../includes/helpers.php';

requireLogin();
requirePermission('ai.manage.knowledge');
header('Location: ' . BASE_URL . 'admin/chatbot-knowledge.php', true, 301);
exit;
/**
 * AI Chatbot Knowledge Updater
 * Applies the authoritative chatbot knowledge set from includes/helpers.php.
 */
include '../includes/session.php';
include '../database/config.php';
include '../includes/helpers.php';

requireAdmin();
requirePermission('ai.use');

$page_title = 'AI Chatbot Knowledge Updated';
$ok = chatbotKnowledgeSeedDefaults($conn);
$items = [];

if ($ok) {
    $result = $conn->query("SELECT topic, category, status FROM chatbot_knowledge ORDER BY topic ASC");
    while ($result && $row = $result->fetch_assoc()) {
        $items[] = $row;
    }
    createAuditLog($conn, $_SESSION['user_id'] ?? 0, 'SYNC_CHATBOT_KNOWLEDGE', 'chatbot_knowledge', 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/holy-theme.css">
    <link rel="stylesheet" href="../assets/css/premium-parish.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?php echo file_exists(__DIR__ . '/../assets/css/theme.css') ? filemtime(__DIR__ . '/../assets/css/theme.css') : time(); ?>">
</head>
<body class="church-theme">
    <div class="premium-admin-shell">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="premium-admin-content">
            <section class="premium-admin-hero">
                <div>
                    <span class="premium-pill landing-eyebrow"><i class="fas fa-robot"></i> TUGON AI</span>
                    <h1>AI Chatbot Knowledge Updated</h1>
                    <p>The official chatbot knowledge base has been synchronized with the latest approved dataset.</p>
                </div>
            </section>

            <?php if ($ok): ?>
                <div class="alert alert-success">
                    <strong>Update complete.</strong> The chatbot will now use this official knowledge set.
                </div>
            <?php else: ?>
                <div class="alert alert-danger">
                    <strong>Update failed.</strong> Please check the database connection and try again.
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Active Knowledge Topics</strong>
                    <a href="chatbot-knowledge.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-database"></i> Manage Knowledge Base
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>Topic</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td><?php echo e($item['topic']); ?></td>
                                        <td><?php echo e(ucfirst($item['category'])); ?></td>
                                        <td><span class="badge bg-success"><?php echo e($item['status']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$items): ?>
                                    <tr><td colspan="3" class="text-muted text-center">No knowledge topics found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
