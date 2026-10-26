<?php
namespace Lab;

use Aws\Sqs\SqsClient;
use Throwable;

final class QueueWorker
{
    private bool $running = true;

    public function __construct(
        private SqsClient $sqs,
        private string $queueUrl,
        private MarketingProcessor $processor,
        private ActivityLogger $logger
    ) {}

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(int $maxIterations = 0): void
    {
        echo "Starting QueueWorker on queue: {$this->queueUrl}\n";
        echo "Waiting for messages (Press Ctrl+C to stop)...\n";

        $iterations = 0;
        $idleCount = 0;

        while ($this->running) {
            if ($maxIterations > 0 && $iterations >= $maxIterations) {
                break;
            }

            try {
                $result = $this->sqs->receiveMessage([
                    'QueueUrl'            => $this->queueUrl,
                    'MaxNumberOfMessages' => 5,
                    'WaitTimeSeconds'     => 10,
                    'AttributeNames'      => ['All'],
                ]);

                $messages = $result->get('Messages');
                if (empty($messages)) {
                    $idleCount++;
                    if ($idleCount >= 3 && $maxIterations === 0 && $iterations > 0) {
                        echo "Queue is empty. Exiting receive loop.\n";
                        break;
                    }
                    continue;
                }

                $idleCount = 0;
                foreach ($messages as $message) {
                    $this->handle($message);
                    $iterations++;
                    if ($maxIterations > 0 && $iterations >= $maxIterations) {
                        break 2;
                    }
                }
            } catch (Throwable $e) {
                echo "Error receiving messages from SQS: {$e->getMessage()}\n";
                sleep(2);
            }
        }

        echo "QueueWorker stopped. Total processed: {$iterations}\n";
    }

    public function handle(array $message): void
    {
        $sqsMessageId = $message['MessageId'];
        $receiptHandle = $message['ReceiptHandle'];

        $this->logger->record('received', [
            'sqsMessageId' => $sqsMessageId,
        ]);

        $job = null;
        try {
            $job = json_decode($message['Body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->logger->record('processing_failed', [
                'sqsMessageId' => $sqsMessageId,
                'phase'        => 'decode',
                'error'        => $e->getMessage(),
            ]);
            echo "Failed to decode JSON payload for SQS message {$sqsMessageId}: {$e->getMessage()}\n";
            return;
        }

        try {
            // TODO W1: Invoke the injected processor with this job
            $this->processor->process($job);

            // TODO M3: Change the operation phase to 'delete' before acknowledgment
            $phase = 'delete';

            // TODO W2: Delete the SQS message using the queue URL and ReceiptHandle
            $this->sqs->deleteMessage([
                'QueueUrl'      => $this->queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]);

            // TODO M3: Log 'deleted' only after DeleteMessage succeeds
            $this->logger->record('deleted', [
                'requestId'    => $job['requestId'] ?? null,
                'campaignId'   => $job['campaignId'] ?? null,
                'customerId'   => $job['customerId'] ?? null,
                'sqsMessageId' => $sqsMessageId,
                'phase'        => $phase,
            ]);

            echo "Message {$sqsMessageId} acknowledged and deleted from queue.\n";
        } catch (Throwable $e) {
            if (isset($phase) && $phase === 'delete') {
                $this->logger->record('ack_failed', [
                    'requestId'    => $job['requestId'] ?? null,
                    'campaignId'   => $job['campaignId'] ?? null,
                    'customerId'   => $job['customerId'] ?? null,
                    'sqsMessageId' => $sqsMessageId,
                    'error'        => $e->getMessage(),
                ]);
                echo "Failed to delete SQS message {$sqsMessageId}: {$e->getMessage()}\n";
            } else {
                // If processing fails, leave the message undeleted so it retries
                $this->logger->record('processing_failed', [
                    'requestId'    => $job['requestId'] ?? null,
                    'campaignId'   => $job['campaignId'] ?? null,
                    'customerId'   => $job['customerId'] ?? null,
                    'sqsMessageId' => $sqsMessageId,
                    'phase'        => 'process',
                    'error'        => $e->getMessage(),
                ]);
                echo "Processing failed for message {$sqsMessageId}: {$e->getMessage()}\n";
            }
        }
    }
}
