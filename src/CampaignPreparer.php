<?php
namespace Lab;

use PDO;
use RuntimeException;

final class CampaignPreparer
{
    public function __construct(private PDO $db)
    {
    }

    public function prepare(
        string $campaignId,
        string $promoCode,
        string $subject = 'Laboratory promotion',
        string $body = 'You have been selected to receive our promotional offer.'
    ): void {
        // TODO C3: Before selecting, check existing outbox rows for the campaign.
        $checkStmt = $this->db->prepare("SELECT COUNT(*) FROM marketing_outbox WHERE campaign_id = ?");
        $checkStmt->execute([$campaignId]);
        $existingCount = (int) $checkStmt->fetchColumn();

        if ($existingCount === 50) {
            echo "Campaign '{$campaignId}' already has 50 prepared requests. Keeping original selection unchanged.\n";
            return;
        }

        if ($existingCount > 0 && $existingCount !== 50) {
            throw new RuntimeException("Unexpected partial selection of {$existingCount} requests exists for campaign '{$campaignId}'. Inconsistency detected; aborting.");
        }

        // TODO C2: Select exactly 50 distinct customers at random from those with marketing_opt_in=1 without replacement
        $selectSql = "SELECT customer_id FROM customers WHERE marketing_opt_in = 1 ORDER BY RAND() LIMIT 50";
        $selectedCustomers = $this->db->query($selectSql)->fetchAll(PDO::FETCH_COLUMN);

        if (count($selectedCustomers) !== 50) {
            throw new RuntimeException("Could not select 50 eligible opted-in customers. Found: " . count($selectedCustomers));
        }

        $createdAt = gmdate('Y-m-d H:i:s');
        $insertSql = "INSERT INTO marketing_outbox (request_id, campaign_id, customer_id, promo_code, subject, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $insertStmt = $this->db->prepare($insertSql);

        $this->db->beginTransaction();
        try {
            foreach ($selectedCustomers as $customerId) {
                // Generate a 32-character hexadecimal request ID using random_bytes() and bin2hex()
                $requestId = bin2hex(random_bytes(16));
                $emailBody = "{$body} Use promo code: {$promoCode}";

                // TODO C1: Execute prepared insert with seven values in order:
                // request ID, campaign ID, customer ID, promo code, subject, body, and creation time
                $insertStmt->execute([
                    $requestId,
                    $campaignId,
                    $customerId,
                    $promoCode,
                    $subject,
                    $emailBody,
                    $createdAt
                ]);
            }
            $this->db->commit();
            echo "Successfully prepared 50 campaign requests for '{$campaignId}' with promo code '{$promoCode}'.\n";
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
