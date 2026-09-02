<?php
/**
 * Announcement Service - Manages announcement lifecycle, audience targeting, and broadcast dispatch.
 */
require_once __DIR__ . '/NotificationService.php';

final class AnnouncementService
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function configure(int $id, string $mode, ?string $publishAt, ?string $expiresAt, string $audience, array $values, int $actor): void
    {
        if (!in_array($mode, ['draft', 'later', 'now'], true)) {
            throw new DomainException('Invalid publication mode.');
        }
        if (!in_array($audience, ['everyone', 'district', 'chapel', 'selected_users'], true)) {
            throw new DomainException('Invalid audience.');
        }
        if ($mode === 'later' && (!$publishAt || strtotime($publishAt) === false || strtotime($publishAt) <= time())) {
            throw new DomainException('Scheduled publication must be in the future.');
        }
        if ($expiresAt && strtotime($expiresAt) === false) {
            throw new DomainException('Invalid expiration date.');
        }
        if ($expiresAt && $publishAt && strtotime($expiresAt) <= strtotime($publishAt)) {
            throw new DomainException('Expiration must be after publication.');
        }

        $life = $mode === 'draft' ? 'draft' : ($mode === 'later' ? 'scheduled' : 'published');
        $status = $life === 'published' ? 'active' : 'inactive';
        $publish = $mode === 'now' ? date('Y-m-d H:i:s') : $publishAt;

        $stmt = $this->db->prepare('UPDATE announcements SET lifecycle_status=?,audience_type=?,publish_at=?,scheduled_at=?,expires_at=?,expiry_date=?,status=?,deleted_at=NULL WHERE announcement_id=?');
        $stmt->bind_param('sssssssi', $life, $audience, $publish, $publish, $expiresAt, $expiresAt, $status, $id);
        $stmt->execute();
        $stmt->close();

        $stmt = $this->db->prepare('DELETE FROM announcement_audiences WHERE announcement_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        if ($audience === 'everyone') {
            $stmt = $this->db->prepare("INSERT INTO announcement_audiences(announcement_id,audience_type) VALUES(?,'everyone')");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        } else {
            if (!$values) {
                throw new DomainException('Choose at least one audience value.');
            }
            foreach (array_unique($values) as $value) {
                if ($audience === 'selected_users') {
                    $uid = (int) $value;
                    if ($uid <= 0) continue;
                    $stmt = $this->db->prepare("INSERT INTO announcement_audiences(announcement_id,audience_type,user_id,audience_value) VALUES(?,'selected_user',?,?)");
                    $string = (string) $uid;
                    $stmt->bind_param('iis', $id, $uid, $string);
                } else {
                    $value = trim((string) $value);
                    if ($value === '') continue;
                    $stmt = $this->db->prepare('INSERT INTO announcement_audiences(announcement_id,audience_type,audience_value) VALUES(?,?,?)');
                    $stmt->bind_param('iss', $id, $audience, $value);
                }
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($life === 'published') {
            $this->notify($id, $actor);
        }
    }

    public function tick(int $actor = 0): array
    {
        $published = $expired = 0;
        $ids = $this->db->query("SELECT announcement_id FROM announcements WHERE lifecycle_status='scheduled' AND publish_at<=NOW() AND deleted_at IS NULL")->fetch_all(MYSQLI_ASSOC);
        foreach ($ids as $r) {
            $id = (int) $r['announcement_id'];
            $stmt = $this->db->prepare("UPDATE announcements SET lifecycle_status='published',status='active',published_date=NOW() WHERE announcement_id=? AND lifecycle_status='scheduled'");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            if ($stmt->affected_rows) {
                $published++;
                $this->notify($id, $actor);
            }
            $stmt->close();
        }
        $result = $this->db->query("UPDATE announcements SET lifecycle_status='expired',status='inactive' WHERE lifecycle_status='published' AND expires_at IS NOT NULL AND expires_at<=NOW()");
        $expired = $result ? $this->db->affected_rows : 0;
        return compact('published', 'expired');
    }

    public function archive(int $id, string $reason, int $actor): void
    {
        if (strlen(trim($reason)) < 5) {
            throw new DomainException('An archive reason is required.');
        }
        $stmt = $this->db->prepare("UPDATE announcements SET lifecycle_status='archived',status='inactive',is_pinned=0,archived_at=NOW(),archived_by=?,archive_reason=?,deleted_at=COALESCE(deleted_at,NOW()) WHERE announcement_id=?");
        $stmt->bind_param('isi', $actor, $reason, $id);
        $stmt->execute();
        $stmt->close();
    }

    public function canView(int $id, int $user): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM announcements a JOIN users u ON u.id=? WHERE a.announcement_id=? AND a.lifecycle_status='published' AND a.publish_at<=NOW() AND (a.expires_at IS NULL OR a.expires_at>NOW()) AND (a.audience_type='everyone' OR a.audience_type IS NULL OR EXISTS(SELECT 1 FROM announcement_audiences aa WHERE aa.announcement_id=a.announcement_id AND ((aa.audience_type='selected_user' AND aa.user_id=u.id) OR (aa.audience_type IN('district','chapel') AND aa.audience_value=u.chapel_district))))");
        $stmt->bind_param('ii', $user, $id);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    }

    public function notifyNow(int $id, int $actor): void
    {
        $this->notify($id, $actor);
    }

    private function notify(int $id, int $actor): void
    {
        $stmt = $this->db->prepare('SELECT title, content FROM announcements WHERE announcement_id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $a = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$a) return;

        $users = $this->recipients($id);
        $notifications = new NotificationService($this->db);
        foreach ($users as $uid) {
            $notifications->create(
                $uid,
                'announcement_published',
                ['announcement_title' => $a['title'], 'title' => $a['title'], 'message' => $a['content']],
                'announcement',
                $id,
                'announcement.view',
                'published',
                true
            );
        }

        if ($actor > 0 && function_exists('createAuditLog')) {
            createAuditLog($this->db, $actor, 'PUBLISH_ANNOUNCEMENT', 'announcements', $id, null, ['recipient_count' => count($users)]);
        }
    }

    private function recipients(int $id): array
    {
        $stmt = $this->db->prepare("SELECT DISTINCT u.id FROM users u JOIN announcements a ON a.announcement_id=? WHERE (u.role IN ('user', 'parishioner', 'member') OR u.role IS NULL OR u.role = '' OR EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id WHERE ur.user_id=u.id AND r.role_key='parishioner')) AND u.status='active' AND (a.audience_type='everyone' OR a.audience_type IS NULL OR EXISTS(SELECT 1 FROM announcement_audiences aa WHERE aa.announcement_id=a.announcement_id AND ((aa.audience_type='selected_user' AND aa.user_id=u.id) OR (aa.audience_type IN('district','chapel') AND aa.audience_value=u.chapel_district))))");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return array_map('intval', array_column($rows, 'id'));
    }
}
