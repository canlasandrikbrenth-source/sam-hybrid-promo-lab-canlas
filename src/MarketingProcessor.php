<?php
namespace Lab;

use InvalidArgumentException;
use PDO;
use Throwable;

final class MarketingProcessor
{
    public function __construct(
        private PDO $db,
        private EmailHistory $history,
        private EmailSender $sender,
        private ActivityLogger $logger,
        private int $sendDelaySeconds = 0
    ) {}

    public function process(array $job): void
    {
        // Validate required job fields
        $requiredKeys = ['requestId', 'campaignId', 'customerId', 'promoCode', 'subject', 'body'];
        foreach ($requiredKeys as $k) {
            if (empty($job[$k])) {
                throw new InvalidArgumentException("Missing required job field: {$k}");
            }
        }

        $campaignId = $job['campaignId'];
        $customerId = $job['customerId'];
        $requestId  = $job['requestId'];

        // Task P1: Use EmailHistory::isComplete() to return early for an already SENT or SKIPPED pair.
        // Record duplicate_skipped before returning.
        if ($this->history->isComplete($campaignId, $customerId)) {
            $this->logger->record('duplicate_skipped', [
                'requestId'  => $requestId,
                'campaignId' => $campaignId,
                'customerId' => $customerId,
            ]);
            echo "Skipping duplicate processing for campaign {$campaignId}, customer {$customerId}.\n";
            return;
        }

        // Task M2: Record processing_started, then sent or skipped only after the corresponding
        // local outcome is saved. Include requestId, campaignId, and customerId.
        $this->logger->record('processing_started', [
            'requestId'  => $requestId,
            'campaignId' => $campaignId,
            'customerId' => $customerId,
        ]);

        // Task P2: Look up the local customer using a prepared SELECT and the job's customerId
        $stmt = $this->db->prepare("SELECT customer_id, full_name, email, marketing_opt_in FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();

        // Task P3: Save SKIPPED and return if the customer is missing or not opted in. Include the reason.
        if (!$customer) {
            $reason = 'Customer record not found';
            $this->history->recordOutcome(
                campaignId: $campaignId,
                customerId: $customerId,
                requestId: $requestId,
                promoCode: $job['promoCode'],
                subject: $job['subject'],
                status: 'SKIPPED',
                reason: $reason
            );
            $this->logger->record('skipped', [
                'requestId'  => $requestId,
                'campaignId' => $campaignId,
                'customerId' => $customerId,
                'reason'     => $reason,
            ]);
            echo "Customer {$customerId} not found. Stored SKIPPED.\n";
            return;
        }

        if ((int) $customer['marketing_opt_in'] !== 1) {
            $reason = 'Customer has not opted in to marketing emails';
            $this->history->recordOutcome(
                campaignId: $campaignId,
                customerId: $customerId,
                requestId: $requestId,
                promoCode: $job['promoCode'],
                subject: $job['subject'],
                status: 'SKIPPED',
                recipientEmail: $customer['email'],
                reason: $reason
            );
            $this->logger->record('skipped', [
                'requestId'  => $requestId,
                'campaignId' => $campaignId,
                'customerId' => $customerId,
                'reason'     => $reason,
            ]);
            echo "Customer {$customerId} is opted out. Stored SKIPPED.\n";
            return;
        }

        // Task P4 & P5: Send email and record SENT
        try {
            // Task P4: Call the injected EmailSender and retain the returned MIME Message-ID
            $messageId = $this->sender->send($customer, $job);
            $sentAt = gmdate('Y-m-d H:i:s');

            // Task P5: Save SENT with recipient, provider, Message-ID, and UTC sent_at
            $this->history->recordOutcome(
                campaignId: $campaignId,
                customerId: $customerId,
                requestId: $requestId,
                promoCode: $job['promoCode'],
                subject: $job['subject'],
                status: 'SENT',
                recipientEmail: $customer['email'],
                provider: 'Mailtrap',
                messageId: $messageId,
                sentAt: $sentAt
            );

            // Record sent log event after saving outcome
            $this->logger->record('sent', [
                'requestId'  => $requestId,
                'campaignId' => $campaignId,
                'customerId' => $customerId,
                'messageId'  => $messageId,
            ]);

            echo "Successfully sent email to {$customer['customer_id']} ({$customer['email']}), MsgId: {$messageId}\n";

            if ($this->sendDelaySeconds > 0) {
                sleep($this->sendDelaySeconds);
            }
        } catch (Throwable $e) {
            // Input validation and FAILED-save/rethrow pattern
            $this->history->recordOutcome(
                campaignId: $campaignId,
                customerId: $customerId,
                requestId: $requestId,
                promoCode: $job['promoCode'],
                subject: $job['subject'],
                status: 'FAILED',
                recipientEmail: $customer['email'] ?? null,
                provider: 'Mailtrap',
                reason: $e->getMessage()
            );

            $this->logger->record('processing_failed', [
                'requestId'  => $requestId,
                'campaignId' => $campaignId,
                'customerId' => $customerId,
                'error'      => $e->getMessage(),
            ]);

            // Rethrow so the message is NOT acknowledged by the worker
            throw $e;
        }
    }
}
