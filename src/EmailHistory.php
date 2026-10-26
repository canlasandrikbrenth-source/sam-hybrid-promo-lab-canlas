<?php
namespace Lab;

use PDO;

final class EmailHistory
{
    public function __construct(private PDO $db)
    {
    }

    public function isComplete(string $campaignId, string $customerId): bool
    {
        $stmt = $this->db->prepare("
            SELECT status FROM sent_marketing_emails
            WHERE campaign_id = ? AND customer_id = ?
        ");
        $stmt->execute([$campaignId, $customerId]);
        $status = $stmt->fetchColumn();

        return in_array($status, ['SENT', 'SKIPPED'], true);
    }

    public function recordOutcome(
        string $campaignId,
        string $customerId,
        string $requestId,
        string $promoCode,
        string $subject,
        string $status,
        ?string $recipientEmail = null,
        ?string $provider = null,
        ?string $messageId = null,
        ?string $reason = null,
        ?string $sentAt = null
    ): void {
        $processedAt = gmdate('Y-m-d H:i:s');

        $sql = "INSERT INTO sent_marketing_emails (
                    campaign_id, customer_id, request_id, promo_code,
                    recipient_email, subject, status, provider,
                    message_id, reason, processed_at, sent_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    request_id = VALUES(request_id),
                    promo_code = VALUES(promo_code),
                    recipient_email = VALUES(recipient_email),
                    subject = VALUES(subject),
                    status = VALUES(status),
                    provider = VALUES(provider),
                    message_id = VALUES(message_id),
                    reason = VALUES(reason),
                    processed_at = VALUES(processed_at),
                    sent_at = VALUES(sent_at)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            $campaignId,
            $customerId,
            $requestId,
            $promoCode,
            $recipientEmail,
            $subject,
            $status,
            $provider,
            $messageId,
            $reason,
            $processedAt,
            $sentAt
        ]);
    }
}
