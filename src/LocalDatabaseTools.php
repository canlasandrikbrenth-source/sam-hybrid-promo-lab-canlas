<?php
namespace Lab;

use PDO;
use RuntimeException;

final class LocalDatabaseTools
{
    public function __construct(private PDO $db)
    {
    }

    public function initSchema(string $schemaPath): void
    {
        if (!file_exists($schemaPath)) {
            throw new RuntimeException("Schema file not found at: {$schemaPath}");
        }

        $sql = file_get_contents($schemaPath);
        $this->db->exec($sql);
        echo "Database schema initialized successfully from {$schemaPath}.\n";
    }

    public function inspect(): void
    {
        echo "========================================\n";
        echo "      DATABASE INSPECTION REPORT        \n";
        echo "========================================\n\n";

        // Customers
        $res = $this->db->query("SELECT COUNT(*) as total FROM customers")->fetch();
        $total = $res['total'];
        echo "CUSTOMERS TABLE: Total = {$total}\n";
        if ($total > 0) {
            $stmt = $this->db->query("SELECT marketing_opt_in, COUNT(*) as cnt FROM customers GROUP BY marketing_opt_in ORDER BY marketing_opt_in");
            while ($row = $stmt->fetch()) {
                $status = ($row['marketing_opt_in'] == 1) ? 'Opted IN (1)' : 'Opted OUT (0)';
                echo "  - {$status}: {$row['cnt']}\n";
            }
            echo "\nSample records (LIMIT 5):\n";
            $sample = $this->db->query("SELECT customer_id, full_name, email, marketing_opt_in, created_at FROM customers ORDER BY customer_id LIMIT 5");
            while ($c = $sample->fetch()) {
                echo "  {$c['customer_id']} | {$c['full_name']} | {$c['email']} | OptIn: {$c['marketing_opt_in']}\n";
            }
        }

        // Outbox
        echo "\n----------------------------------------\n";
        $resOut = $this->db->query("SELECT COUNT(*) as total FROM marketing_outbox")->fetch();
        echo "MARKETING OUTBOX: Total = {$resOut['total']}\n";
        if ($resOut['total'] > 0) {
            $stmtOut = $this->db->query("SELECT status, COUNT(*) as cnt FROM marketing_outbox GROUP BY status");
            while ($row = $stmtOut->fetch()) {
                echo "  - Status {$row['status']}: {$row['cnt']}\n";
            }
            $sampleOut = $this->db->query("SELECT request_id, campaign_id, customer_id, promo_code, status, sqs_message_id FROM marketing_outbox LIMIT 5");
            echo "Sample records (LIMIT 5):\n";
            while ($o = $sampleOut->fetch()) {
                echo "  Req: {$o['request_id']} | Camp: {$o['campaign_id']} | Cust: {$o['customer_id']} | Promo: {$o['promo_code']} | Status: {$o['status']} | SQS: " . ($o['sqs_message_id'] ?: 'null') . "\n";
            }
        }

        // Sent Marketing Emails
        echo "\n----------------------------------------\n";
        $resSent = $this->db->query("SELECT COUNT(*) as total FROM sent_marketing_emails")->fetch();
        echo "SENT MARKETING EMAILS (HISTORY): Total = {$resSent['total']}\n";
        if ($resSent['total'] > 0) {
            $stmtSent = $this->db->query("SELECT status, COUNT(*) as cnt FROM sent_marketing_emails GROUP BY status");
            while ($row = $stmtSent->fetch()) {
                echo "  - Status {$row['status']}: {$row['cnt']}\n";
            }
            $sampleSent = $this->db->query("SELECT campaign_id, customer_id, request_id, recipient_email, status, provider, message_id, reason, processed_at FROM sent_marketing_emails LIMIT 5");
            echo "Sample records (LIMIT 5):\n";
            while ($s = $sampleSent->fetch()) {
                echo "  Camp: {$s['campaign_id']} | Cust: {$s['customer_id']} | Status: {$s['status']} | MsgId: {$s['message_id']} | Reason: {$s['reason']}\n";
            }
        }
        echo "========================================\n\n";
    }
}
