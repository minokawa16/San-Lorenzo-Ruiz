<?php
/**
 * Email Template Manager
 * Manages email templates for various notification scenarios
 */

class EmailTemplateManager {
    private $conn;
    private $logger;

    public function __construct($database_connection, $logger = null) {
        $this->conn = $database_connection;
        $this->logger = $logger;
    }

    /**
     * Get email template by key
     * @param string $template_key Template key identifier
     * @return array|null Template data
     */
    public function getTemplateByKey($template_key) {
        $sql = "SELECT * FROM email_templates WHERE template_key = ? AND is_active = 1 LIMIT 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('s', $template_key);
        $stmt->execute();
        $result = $stmt->get_result();
        $template = $result->fetch_assoc();
        $stmt->close();

        return $template;
    }

    /**
     * Render email template with variable substitution
     * @param string $template_key Template key
     * @param array $variables Variables to substitute
     * @return array Rendered email with subject and body
     */
    public function renderTemplate($template_key, $variables = []) {
        $template = $this->getTemplateByKey($template_key);
        if (!$template) {
            return ['success' => false, 'error' => 'Template not found'];
        }

        $subject = $this->substituteVariables($template['subject_line'], $variables);
        $body = $this->substituteVariables($template['email_body'], $variables);
        $plain_text = $this->substituteVariables($template['plain_text_body'] ?? '', $variables);

        return [
            'success' => true,
            'template_id' => $template['template_id'],
            'subject' => $subject,
            'body' => $body,
            'plain_text' => $plain_text
        ];
    }

    /**
     * Substitute variables in template
     * @param string $text Text with variables
     * @param array $variables Variables to substitute
     * @return string Rendered text
     */
    private function substituteVariables($text, $variables = []) {
        foreach ($variables as $key => $value) {
            $placeholder = '{{' . $key . '}}';
            $text = str_replace($placeholder, htmlspecialchars($value), $text);
        }

        // Clean up any unused placeholders
        $text = preg_replace('/\{\{.*?\}\}/', '', $text);

        return $text;
    }

    /**
     * Get all templates
     * @param bool $active_only Only get active templates
     * @return array List of templates
     */
    public function getAllTemplates($active_only = true) {
        $sql = "SELECT * FROM email_templates";

        if ($active_only) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY recipient_type, template_name ASC";

        $result = $this->conn->query($sql);
        if (!$result) {
            return [];
        }

        $templates = [];
        while ($row = $result->fetch_assoc()) {
            $templates[] = $row;
        }

        return $templates;
    }

    /**
     * Create or update template
     * @param string $template_name Template name
     * @param string $template_key Unique key
     * @param string $subject_line Email subject
     * @param string $email_body Email body
     * @param string $plain_text_body Plain text version
     * @param int $created_by User ID
     * @param string $recipient_type Recipient type
     * @return array Result with template_id
     */
    public function createTemplate(
        $template_name,
        $template_key,
        $subject_line,
        $email_body,
        $plain_text_body = '',
        $created_by = 0,
        $recipient_type = 'user'
    ) {
        // Check if template exists
        $existing = $this->conn->query("SELECT template_id FROM email_templates WHERE template_key = ' $template_key' LIMIT 1");

        if ($existing && $existing->num_rows > 0) {
            return $this->updateTemplate(
                $existing->fetch_assoc()['template_id'],
                $template_name,
                $subject_line,
                $email_body,
                $plain_text_body,
                $created_by
            );
        }

        // Determine available variables
        $template_variables = $this->getAvailableVariables($template_key);

        $sql = "INSERT INTO email_templates 
                (template_name, template_key, subject_line, email_body, plain_text_body, recipient_type, template_variables, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }

        $variables_json = json_encode($template_variables);
        $stmt->bind_param('sssssssi', $template_name, $template_key, $subject_line, $email_body, $plain_text_body, $recipient_type, $variables_json, $created_by);

        if ($stmt->execute()) {
            $template_id = $stmt->insert_id;
            $stmt->close();

            if ($this->logger) {
                $this->logger->logAction($created_by, 'email_templates', $template_id, 'created', 'system', null, "Template '$template_name' created");
            }

            return ['success' => true, 'template_id' => $template_id];
        }

        $stmt->close();
        return ['success' => false, 'error' => 'Failed to create template'];
    }

    /**
     * Update template
     * @param int $template_id Template ID
     * @param string $template_name New name
     * @param string $subject_line New subject
     * @param string $email_body New body
     * @param string $plain_text_body New plain text
     * @param int $changed_by User ID
     * @return array Result
     */
    public function updateTemplate(
        $template_id,
        $template_name,
        $subject_line,
        $email_body,
        $plain_text_body = '',
        $changed_by = 0
    ) {
        // Get old template for version history
        $old_template = $this->conn->query("SELECT subject_line, email_body FROM email_templates WHERE template_id = $template_id LIMIT 1");
        $old_data = $old_template ? $old_template->fetch_assoc() : null;

        $sql = "UPDATE email_templates 
                SET template_name = ?, subject_line = ?, email_body = ?, plain_text_body = ?, updated_at = NOW() 
                WHERE template_id = ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Database error'];
        }

        $stmt->bind_param('ssssi', $template_name, $subject_line, $email_body, $plain_text_body, $template_id);

        if ($stmt->execute()) {
            $stmt->close();

            // Record version history
            if ($old_data) {
                $this->recordTemplateVersion($template_id, $old_data['subject_line'], $old_data['email_body'], $changed_by);
            }

            if ($this->logger) {
                $this->logger->logAction($changed_by, 'email_templates', $template_id, 'updated', 'system', null, "Template updated");
            }

            return ['success' => true, 'template_id' => $template_id];
        }

        $stmt->close();
        return ['success' => false, 'error' => 'Failed to update template'];
    }

    /**
     * Record template version for audit trail
     * @param int $template_id Template ID
     * @param string $subject_line Old subject
     * @param string $email_body Old body
     * @param int $changed_by User ID
     */
    private function recordTemplateVersion($template_id, $subject_line, $email_body, $changed_by) {
        $sql = "INSERT INTO email_template_versions (template_id, subject_line, email_body, changed_by, created_at) 
                VALUES (?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('issi', $template_id, $subject_line, $email_body, $changed_by);
            $stmt->execute();
            $stmt->close();
        }
    }

    /**
     * Log email send
     * @param int $template_id Template ID
     * @param int $recipient_user_id Recipient user ID
     * @param string $recipient_email Recipient email
     * @param string $entity_type Entity type
     * @param int $entity_id Entity ID
     * @param string $subject Subject sent
     * @param string $status Send status
     * @return bool Success status
     */
    public function logEmailSend($template_id, $recipient_user_id, $recipient_email, $entity_type, $entity_id, $subject, $status = 'sent') {
        $sql = "INSERT INTO email_send_log (template_id, recipient_user_id, recipient_email, entity_type, entity_id, subject_sent, send_status, sent_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('iisiii', $template_id, $recipient_user_id, $recipient_email, $entity_type, $entity_id, $subject, $status);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get available variables for template key
     * @param string $template_key Template key
     * @return array Available variables
     */
    private function getAvailableVariables($template_key) {
        $variables_map = [
            'request_submitted' => ['user_name', 'request_id', 'request_type', 'submission_date', 'tracking_link'],
            'request_approved' => ['user_name', 'request_id', 'approval_date', 'next_steps', 'tracking_link'],
            'request_rejected' => ['user_name', 'request_id', 'rejection_reason', 'appeal_process', 'support_email'],
            'payment_received' => ['user_name', 'request_id', 'amount', 'payment_date', 'receipt_id'],
            'payment_pending' => ['user_name', 'request_id', 'amount', 'payment_due_date', 'payment_instructions'],
            'document_ready' => ['user_name', 'document_type', 'pickup_date', 'location', 'contact_person'],
            'certificate_ready' => ['user_name', 'certificate_type', 'pickup_date', 'certificate_id', 'location'],
            'certificate_released' => ['user_name', 'certificate_type', 'release_date', 'download_link', 'verification_code'],
            'announcement' => ['title', 'content', 'date', 'location', 'contact_info'],
            'event_reminder' => ['event_name', 'event_date', 'event_time', 'location', 'reminder_message'],
        ];

        return $variables_map[$template_key] ?? [];
    }

    /**
     * Get email send log
     * @param int $limit Limit results
     * @param int $offset Offset
     * @return array Email log entries
     */
    public function getEmailSendLog($limit = 100, $offset = 0) {
        $sql = "SELECT esl.*, et.template_name, u.fullname 
                FROM email_send_log esl
                LEFT JOIN email_templates et ON esl.template_id = et.template_id
                LEFT JOIN users u ON esl.recipient_user_id = u.id
                ORDER BY esl.sent_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        $log = [];

        while ($row = $result->fetch_assoc()) {
            $log[] = $row;
        }

        $stmt->close();
        return $log;
    }

    /**
     * Seed default templates
     * @return bool Success status
     */
    public function seedDefaultTemplates() {
        $defaults = [
            [
                'name' => 'Request Submitted',
                'key' => 'request_submitted',
                'subject' => 'Your {{request_type}} Request - Confirmation #{{request_id}}',
                'body' => 'Dear {{user_name}},

Your {{request_type}} request has been successfully submitted.

Request Reference: {{request_id}}
Submission Date: {{submission_date}}

You can track your request status here: {{tracking_link}}

Thank you,
Parish Management System'
            ],
            [
                'name' => 'Request Approved',
                'key' => 'request_approved',
                'subject' => 'Your {{request_type}} Request - APPROVED #{{request_id}}',
                'body' => 'Dear {{user_name}},

Good news! Your {{request_type}} request has been approved.

Request ID: {{request_id}}
Approval Date: {{approval_date}}

Next Steps: {{next_steps}}

Track Progress: {{tracking_link}}

Thank you,
Parish Management System'
            ],
            [
                'name' => 'Payment Received',
                'key' => 'payment_received',
                'subject' => 'Payment Received - Receipt #{{receipt_id}}',
                'body' => 'Dear {{user_name}},

Your payment has been successfully received.

Amount: {{amount}}
Receipt ID: {{receipt_id}}
Date: {{payment_date}}

Thank you for your contribution.

Parish Management System'
            ],
            [
                'name' => 'Certificate Ready',
                'key' => 'certificate_ready',
                'subject' => '{{certificate_type}} Certificate Ready for Pickup',
                'body' => 'Dear {{user_name}},

Your {{certificate_type}} certificate is now ready for pickup.

Certificate ID: {{certificate_id}}
Pickup Location: {{location}}
Pickup Date Available: {{pickup_date}}

Please bring valid ID for verification.

Parish Management System'
            ],
        ];

        foreach ($defaults as $template) {
            $this->createTemplate(
                $template['name'],
                $template['key'],
                $template['subject'],
                $template['body'],
                '',
                1,
                'user'
            );
        }

        return true;
    }
}
?>
