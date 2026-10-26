# Systems Administration and Maintenance - Hybrid Email Laboratory - Andrik Canlas

**Student Name:** Andrik Canlas  
**Student ID / Number:** 11-0251-543  
**Course & Section:** Systems Administration and Maintenance (SAM10)  
**Date:** October 26, 2026  
**GitHub Repository:** `https://github.com/andrikcanlas/sam-hybrid-promo-lab.git`

---

## 1. Overview and Architecture

The application demonstrates an enterprise-grade, decoupled hybrid architecture combining local persistence and data generation with cloud-native queuing, monitoring, and email capture.

### Architectural Components
- **Local Data Tier (MySQL 8.0 / MariaDB):** Stores the 10,000 fictional customer population, the local staging outbox (`marketing_outbox`), and the campaign audit history (`sent_marketing_emails`). To protect customer privacy, customer PII (names and email addresses) remains strictly local and is never published to the cloud queue.
- **Message Broker Tier (Amazon SQS Standard Queue):** Decouples campaign preparation from email processing. Holds lightweight job payloads containing only non-sensitive identifiers (`requestId`, `campaignId`, `customerId`, `promoCode`, `subject`, `body`).
- **Resilience Tier (Amazon SQS Dead-Letter Queue - DLQ):** Automatically captures messages that fail processing repeatedly (after `maxReceiveCount = 3` receives), isolating poisoning messages from blocking valid traffic.
- **Monitoring & Observability Tier (Amazon CloudWatch & Structured Logs):** Provides end-to-end visibility through a dedicated dashboard displaying sent, received, deleted, and in-flight message metrics, message age, and DLQ backlog alarms, complemented by UTC JSON-Lines local logging (`logs/events.jsonl`).
- **Processing Tier (PHP OOP Worker & SMTP Sandbox):** Pulls messages from SQS, verifies consent and idempotency against the local database, submits emails to Mailtrap SMTP, writes audit outcomes, and acknowledges messages via receipt handle deletion.

### Architecture Workflow Diagram
```
+-----------------------------------------------------------------------------------+
|                                   YOUR COMPUTER                                   |
|                                                                                   |
|  +-------------------+       +------------------------+       +----------------+  |
|  | CustomerSeeder    | ----> | Local MySQL Database   | ----> | QueuePublisher |  |
|  | Faker Generation  |       | customers              |       | (enqueue.php)  |  |
|  +-------------------+       | marketing_outbox       |       +-------+--------+  |
|                              | sent_marketing_emails  |               |           |
|  +-------------------+       +-----------+------------+               |           |
|  | CampaignPreparer  | ------------------+                            |           |
|  | 50 Distinct OptIn |                                                |           |
|  +-------------------+                                                |           |
+-----------------------------------------------------------------------|-----------+
                                                                        |
                                                         AWS SDK Upload | JSON Payload
                                                                        v
+-----------------------------------------------------------------------------------+
|                                   AMAZON WEB SERVICES                             |
|                                                                                   |
|                      +---------------------------------------+                    |
|                      | Amazon SQS: Main Standard Queue       |                    |
|                      | MarketingEmailQueue-andrik-canlas     |                    |
|                      +-------------------+-------------------+                    |
|                                          |                                        |
|                          ReceiveMessage  | Redrive Policy                         |
|                          (Long Polling)  | (maxReceiveCount = 3)                  |
|                                          v                                        |
|                      +---------------------------------------+                    |
|                      | Amazon SQS: Dead-Letter Queue (DLQ)   |                    |
|                      | MarketingEmailDLQ-andrik-canlas       |                    |
|                      +-------------------+-------------------+                    |
|                                          |                                        |
|                                          v                                        |
|                      +---------------------------------------+                    |
|                      | CloudWatch Alarms & Metrics Dashboard |                    |
|                      | MarketingLab-andrik-canlas            |                    |
|                      +---------------------------------------+                    |
+------------------------------------------|----------------------------------------+
                                           |
                                           v
+-----------------------------------------------------------------------------------+
|                                   YOUR COMPUTER                                   |
|                                                                                   |
|  +------------------+         +----------------------+       +-----------------+  |
|  | QueueWorker      | ------> | MarketingProcessor   | ----> | EmailSender     |  |
|  | (worker.php)     |         | - Consent & Dup Check|       | (PHPMailer SMTP)|  |
|  | - DeleteMessage  |         | - Save Local Outcome |       +--------+--------+  |
|  +------------------+         +----------------------+                |           |
|                                                                       |           |
|  +------------------+                                                 v           |
|  | ActivityLogger   |                                        +-----------------+  |
|  | logs/events.jsonl|                                        | Mailtrap Sandbox|  |
|  +------------------+                                        | SMTP Capture    |  |
+-----------------------------------------------------------------------------------+
```

---

## 2. OOP Completion and Responsibilities

### Class Responsibilities Table
| Class | Responsibility |
|---|---|
| `Database` | Opens a local PDO connection to MySQL with UTF-8 character encoding, exceptions enabled, and UTC timezone set. |
| `Settings` | Reads environment variables and configures AWS SQS client credentials and region. |
| `LocalDatabaseTools` | Executes `schema.sql` table definitions and outputs detailed inspection metrics. |
| `CustomerSeeder` | Generates 10,000 fictional customer records using Faker and enforces 80/20 opt-in distribution. |
| `CampaignPreparer` | Selects 50 random eligible recipients once, stages them in `marketing_outbox`, and ensures idempotency. |
| `QueuePublisher` | Publishes PENDING requests to Amazon SQS, records SQS Message IDs, and updates status to QUEUED. |
| `EmailSender` | Assembles personalized campaign emails and delivers them via SMTP, returning the MIME Message-ID. |
| `EmailHistory` | Verifies prior completion status (`isComplete`) and records outcome rows into `sent_marketing_emails`. |
| `MarketingProcessor` | Validates payloads, re-checks customer consent, dispatches emails, and manages audit persistence. |
| `QueueWorker` | Executes SQS receive polling loop, invokes `MarketingProcessor`, and acknowledges via `deleteMessage`. |
| `ActivityLogger` | Appends UTC timestamps and operational events into `logs/events.jsonl` without sensitive PII. |

### Completed Implementation Excerpts

#### 1. Constructor Injection Example (`MarketingProcessor`)
Constructor injection decouples classes from global variables or hardcoded dependencies, facilitating testing, maintenance, and adherence to the Single Responsibility Principle:

```php
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
...
```
*Explanation:* Instead of initializing its own database connection or creating global logger instances internally, `MarketingProcessor` declares all dependencies as explicit constructor arguments. This allows external orchestrators (like `worker.php`) to inject pre-configured, shared database connections and mockable clients.

#### 2. Customer Seeding (`src/CustomerSeeder.php` - Tasks S1, S2)
```php
for ($i = 1; $i <= $count; $i++) {
    $customerId = sprintf('CUST-%05d', $i);
    $email = sprintf('customer%05d@example.com', $i);

    // TODO S1: Generate a name using the injected Faker object
    $name = $this->faker->name();

    // TODO S2: Assign consent 1 for customers 1-8,000 and 0 for customers 8,001-10,000
    $consent = ($i <= 8000) ? 1 : 0;

    $stmt->execute([$customerId, $name, $email, $consent, $createdAt]);
...
```

#### 3. Campaign Selection & Outbox Staging (`src/CampaignPreparer.php` - Tasks C1, C2, C3)
```php
// TODO C3: Before selecting, check existing outbox rows for the campaign
$checkStmt = $this->db->prepare("SELECT COUNT(*) FROM marketing_outbox WHERE campaign_id = ?");
$checkStmt->execute([$campaignId]);
$existingCount = (int) $checkStmt->fetchColumn();

if ($existingCount === 50) {
    echo "Campaign '{$campaignId}' already has 50 prepared requests. Keeping original selection unchanged.\n";
    return;
}

if ($existingCount > 0 && $existingCount !== 50) {
    throw new RuntimeException("Unexpected partial selection of {$existingCount} requests exists for campaign '{$campaignId}'.");
}

// TODO C2: Select exactly 50 distinct customers at random from those with marketing_opt_in=1
$selectSql = "SELECT customer_id FROM customers WHERE marketing_opt_in = 1 ORDER BY RAND() LIMIT 50";
$selectedCustomers = $this->db->query($selectSql)->fetchAll(PDO::FETCH_COLUMN);

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
```

#### 4. Queue Publishing (`src/QueuePublisher.php` - Tasks Q1, Q2, M1)
```php
// TODO Q1: Call the injected SQS client's sendMessage() method
$result = $this->sqs->sendMessage([
    'QueueUrl'    => $this->queueUrl,
    'MessageBody' => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$messageId = $result->get('MessageId');
$queuedAt = gmdate('Y-m-d H:i:s');

// TODO Q2: Only after acknowledgment, execute UPDATE with queued_at, MessageId, and request_id
$updateStmt->execute([$queuedAt, $messageId, $row['request_id']]);

// TODO M1: Record queued log event with identifiers
$this->logger->record('queued', [
    'requestId'    => $row['request_id'],
    'campaignId'   => $row['campaign_id'],
    'customerId'   => $row['customer_id'],
    'sqsMessageId' => $messageId,
]);
```

#### 5. Queue Worker Acknowledgment (`src/QueueWorker.php` - Tasks W1, W2, M3)
```php
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
```

---

## 3. MySQL Database Design

The relational database uses MySQL/MariaDB with `InnoDB` storage engine and `utf8mb4` character set.

### Schema Relationships (`schema.sql`)
1. **`customers` Table:**
   - `customer_id` VARCHAR(32) PRIMARY KEY
   - `full_name` VARCHAR(150) NOT NULL
   - `email` VARCHAR(254) NOT NULL UNIQUE
   - `marketing_opt_in` TINYINT NOT NULL DEFAULT 0, CHECK (`marketing_opt_in` IN (0, 1))
   - `created_at` DATETIME NOT NULL (UTC)
2. **`marketing_outbox` Table:**
   - `request_id` CHAR(32) PRIMARY KEY
   - `campaign_id` VARCHAR(64) NOT NULL
   - `customer_id` VARCHAR(32) NOT NULL, FOREIGN KEY referencing `customers(customer_id)`
   - `promo_code` VARCHAR(64) NOT NULL
   - `subject` VARCHAR(255) NOT NULL
   - `body` TEXT NOT NULL
   - `status` ENUM('PENDING', 'QUEUED') NOT NULL DEFAULT 'PENDING'
   - `created_at` DATETIME NOT NULL
   - `queued_at` DATETIME NULL
   - `sqs_message_id` VARCHAR(100) NULL
   - UNIQUE KEY `uq_campaign_customer` (`campaign_id`, `customer_id`)
3. **`sent_marketing_emails` Table:**
   - `campaign_id` VARCHAR(64) NOT NULL
   - `customer_id` VARCHAR(32) NOT NULL
   - `request_id` VARCHAR(100) NOT NULL
   - `promo_code` VARCHAR(64) NOT NULL
   - `recipient_email` VARCHAR(254) NULL
   - `subject` VARCHAR(255) NOT NULL
   - `status` ENUM('SENT', 'SKIPPED', 'FAILED') NOT NULL
   - `provider` VARCHAR(50) NULL
   - `message_id` VARCHAR(255) NULL
   - `reason` TEXT NULL
   - `processed_at` DATETIME NOT NULL
   - `sent_at` DATETIME NULL
   - PRIMARY KEY (`campaign_id`, `customer_id`)
   - *Design Note:* `sent_marketing_emails` uses a logical association rather than an enforced foreign key on `customer_id` so that malformed requests with invalid or nonexistent customer IDs can still be recorded as `SKIPPED`.

---

## 4. Faker Customer Generation and Recipient Selection

### Data Generation Verification
- **Total Population:** 10,000 customers generated with seed `2026`.
- **Consent Distribution:**
  - `marketing_opt_in = 1`: 8,000 customers (80%)
  - `marketing_opt_in = 0`: 2,000 customers (20%)
- **Idempotency:** Re-running `php seed-customers.php` detected the existing 10,000 rows and safely exited without creating duplicates.

### SQL Query Verification Evidence
```sql
SELECT COUNT(*) AS total_customers FROM customers;
-- Result: 10000

SELECT marketing_opt_in, COUNT(*) AS total FROM customers GROUP BY marketing_opt_in;
-- Result:
-- 0 | 2000
-- 1 | 8000

SELECT customer_id, full_name, email, marketing_opt_in FROM customers ORDER BY customer_id LIMIT 5;
-- Result:
-- CUST-00001 | Cora Jenkins    | customer00001@example.com | 1
-- CUST-00002 | Al Barton       | customer00002@example.com | 1
-- CUST-00003 | Mikel Kreiger   | customer00003@example.com | 1
-- CUST-00004 | Wilton Mueller  | customer00004@example.com | 1
-- CUST-00005 | Dr. Syble Purdy | customer00005@example.com | 1
```

### Random Selection Verification
- **Distinct Eligible Recipients:** Exactly 50 distinct opted-in customers were selected via `SELECT customer_id FROM customers WHERE marketing_opt_in = 1 ORDER BY RAND() LIMIT 50`.
- **Ineligible Recipients:** Zero ineligible customers were selected (`ineligible_recipients = 0`).
- **Repeat Preparation:** Running `php prepare-campaign.php CAMP-001 PROMO15` a second time verified that the original selection remained completely unchanged.

```sql
SELECT COUNT(*) AS requests, COUNT(DISTINCT customer_id) AS distinct_recipients FROM marketing_outbox WHERE campaign_id = 'CAMP-001';
-- Result: requests: 50 | distinct_recipients: 50

SELECT COUNT(*) AS ineligible_recipients FROM marketing_outbox o JOIN customers c ON c.customer_id = o.customer_id WHERE o.campaign_id = 'CAMP-001' AND c.marketing_opt_in <> 1;
-- Result: 0
```

---

## 5. IAM and Amazon SQS Setup

### Configured Cloud Resources
- **AWS Region:** `ap-southeast-2` (Asia Pacific - Sydney)
- **Main Queue:** `MarketingEmailQueue-andrik-canlas`
  - URL: `https://sqs.ap-southeast-2.amazonaws.com/408852558600/MarketingEmailQueue-andrik-canlas`
  - Visibility Timeout: `180 seconds`
  - Message Retention: `4 days (345,600 seconds)`
  - Receive Message Wait Time: `20 seconds`
  - Redrive Policy: Dead-letter target ARN `arn:aws:sqs:ap-southeast-2:408852558600:MarketingEmailDLQ-andrik-canlas` with `maxReceiveCount = 3`
- **Dead-Letter Queue (DLQ):** `MarketingEmailDLQ-andrik-canlas`
  - URL: `https://sqs.ap-southeast-2.amazonaws.com/408852558600/MarketingEmailDLQ-andrik-canlas`
  - Message Retention: `14 days (1,209,600 seconds)`
- **IAM Application User:** `marketing-lab-andrik-canlas`
  - ARN: `arn:aws:iam::408852558600:user/marketing-lab-andrik-canlas`
  - Console Access: Disabled
  - Profile configured: `marketing-lab`
- **IAM Permissions Policy:** `MarketingLabSQS-andrik-canlas`
  - ARN: `arn:aws:iam::408852558600:policy/MarketingLabSQS-andrik-canlas`
  - Actions restricted to: `sqs:SendMessage`, `sqs:ReceiveMessage`, `sqs:DeleteMessage`, `sqs:GetQueueAttributes`, `sqs:GetQueueUrl` scoped strictly to the Main Queue and DLQ ARNs.

### Local Profile Verification Output
```bash
$ aws sts get-caller-identity --profile marketing-lab
{
    "UserId": "AIDAV6MMC6MEFDFM46DIZ",
    "Account": "408852558600",
    "Arn": "arn:aws:iam::408852558600:user/marketing-lab-andrik-canlas"
}

$ aws sqs get-queue-attributes --queue-url "https://sqs.ap-southeast-2.amazonaws.com/408852558600/MarketingEmailQueue-andrik-canlas" --attribute-names QueueArn --profile marketing-lab
{
    "Attributes": {
        "QueueArn": "arn:aws:sqs:ap-southeast-2:408852558600:MarketingEmailQueue-andrik-canlas"
    }
}
```

---

## 6. Publishing, Processing, and Operational Traces

### Publishing Evidence
- Command: `php enqueue.php`
- All 50 PENDING records in `marketing_outbox` were serialized into JSON payloads and accepted by Amazon SQS.
- Each outbox record was updated with `status = 'QUEUED'`, a UTC timestamp in `queued_at`, and the Amazon SQS `MessageId`.
- Re-running `enqueue.php` verified idempotency: `No PENDING requests found in marketing_outbox.`

```sql
SELECT status, COUNT(*) AS total FROM marketing_outbox GROUP BY status;
-- Result: QUEUED | 50
```

### Processing Evidence
- Command: `php worker.php`
- The queue worker dequeued all 50 messages, performed customer lookup, validated consent, dispatched emails via SMTP, stored `SENT` outcomes with MIME Message-IDs, and executed `DeleteMessage` using the ReceiptHandle.
- Final History Status:
```sql
SELECT status, COUNT(*) AS total FROM sent_marketing_emails WHERE campaign_id = 'CAMP-001' GROUP BY status;
-- Result: SENT | 50
```

### End-to-End Customer Trace (`CUST-06821`)
Demonstrates that local database outcome persistence strictly preceded queue message deletion:
```json
{"timestamp":"2026-10-26T10:12:38Z","event":"queued","requestId":"0f3d78c864fdfefa71c7a28f57a18637","campaignId":"CAMP-001","customerId":"CUST-06821","sqsMessageId":"8839c275-2111-4896-936b-7962e1407938"}
{"timestamp":"2026-10-26T10:21:26Z","event":"processing_started","requestId":"0f3d78c864fdfefa71c7a28f57a18637","campaignId":"CAMP-001","customerId":"CUST-06821"}
{"timestamp":"2026-10-26T10:21:26Z","event":"sent","requestId":"0f3d78c864fdfefa71c7a28f57a18637","campaignId":"CAMP-001","customerId":"CUST-06821","messageId":"<Me971kVacXDJnyZWbVjkJduKjJfor06F0DCrn8llIc@EAS611-39>"}
{"timestamp":"2026-10-26T10:21:27Z","event":"deleted","requestId":"0f3d78c864fdfefa71c7a28f57a18637","campaignId":"CAMP-001","customerId":"CUST-06821","sqsMessageId":"8839c275-2111-4896-936b-7962e1407938","phase":"delete"}
```

### Sample Captured Email Message
```
To: Bartholome Swift <customer01662@example.com>
From: Marketing Department <marketing@example.com>
Subject: Laboratory promotion
Message-ID: <JIDN46J3DxLPhjKCZxMg4YULZl24jrGHI7bxD8dHEA8@EAS611-39>
MIME-Version: 1.0
Content-Type: text/plain; charset=UTF-8

Hello Bartholome Swift,

You have been selected to receive our promotional offer. Use promo code: PROMO15

Your Exclusive Promo Code: PROMO15

Thank you for subscribing!
```

---

## 7. CloudWatch Monitoring and Alarms

### CloudWatch Dashboard (`MarketingLab-andrik-canlas`)
Contains 4 targeted metric widgets under the `AWS/SQS` namespace:
1. **Main Queue - Operation Counts:** Displays `NumberOfMessagesSent` (50) and `NumberOfMessagesDeleted` (50).
2. **Main Queue - Backlog & In-Flight:** Shows `ApproximateNumberOfMessagesVisible` holding at 50 during stopped state, dropping to 0 upon worker execution.
3. **Main Queue - Message Age:** Tracks `ApproximateAgeOfOldestMessage`, peaking during worker standby and resetting to 0.
4. **Dead-Letter Queue - Backlog:** Monitors `ApproximateNumberOfMessagesVisible` on `MarketingEmailDLQ-andrik-canlas`.

### CloudWatch Alarms
1. **DLQ Alarm (`MarketingLab-DLQ-andrik-canlas`):**
   - Metric: `ApproximateNumberOfMessagesVisible` on DLQ
   - Threshold: `>= 1` for 1 datapoint within 1 minute
   - State: Transitioned to `ALARM` during the controlled failure experiment, returned to `OK` following recovery and queue deletion.
2. **Backlog Alarm (`MarketingLab-Backlog-andrik-canlas`):**
   - Metric: `ApproximateAgeOfOldestMessage` on Main Queue
   - Threshold: `>= 300 seconds` for 1 datapoint within 1 minute
   - State: `OK` (temporarily breached threshold while worker was stopped, demonstrating operational alarm sensitivity).

---

## 8. Incident Record: Controlled Failure Investigation and Recovery

| Incident Field | Record Details |
|---|---|
| **Incident ID** | INC-20261026-SQS-01 |
| **Detection** | Worker logged `processing_failed` events with `phase: decode` and `phase: process`. SQS message `bc10288b-a664-4534-b2ab-413f17ceca5f` exceeded `maxReceiveCount = 3` and was redriven to `MarketingEmailDLQ-andrik-canlas`. CloudWatch alarm `MarketingLab-DLQ-andrik-canlas` evaluated `ApproximateNumberOfMessagesVisible >= 1`. |
| **Identification** | Campaign: `CAMP-BROKEN`, SQS MessageId: `bc10288b-a664-4534-b2ab-413f17ceca5f`, ApproximateReceiveCount: `4`. |
| **Symptoms & Impact** | The payload failed JSON validation and schema integrity check. No email was sent, preserving sandbox quota. The queue worker remained operational without crashing, and other valid messages continued normal processing. |
| **Root Cause** | Malformed JSON and missing required attributes: `requestId`, `customerId`, and `promoCode` were absent from the message body `{"campaignId":"CAMP-BROKEN","subject":"Test","body":"Missing IDs"}`. |
| **Corrective Action** | A valid diagnostic request was formulated under campaign `CAMP-RECOVERY` containing all 6 required fields, assigned to opted-out customer `CUST-08001`. The corrected request was submitted to the main queue. |
| **Recovery Evidence** | The worker received the corrected message, verified that customer `CUST-08001` has `marketing_opt_in = 0`, saved outcome `SKIPPED` in `sent_marketing_emails` with reason `"Customer has not opted in to marketing emails"`, acknowledged and deleted the message without dispatching SMTP traffic. The original malformed message was deleted from the DLQ, restoring DLQ backlog to 0 and CloudWatch alarm to `OK`. |
| **Prevention** | Implement client-side schema validation (e.g. JSON schema validator) in `QueuePublisher` before `sendMessage` to prevent malformed payloads from ever reaching the queue broker. |

---

## 9. Comprehensive Test Results Table

| # | Test / Action | Expected Result | Actual Result | Status | Evidence Reference |
|---|---|---|---|---|---|
| 1 | **Offline Generation** | 10,000 customers generated with 8,000 opted in (1) and 2,000 opted out (0). | Total = 10,000 (Opt-in: 8,000, Opt-out: 2,000). | PASS | `inspect.php` query counts |
| 2 | **Repeat Generation** | Existing customer records are preserved; counts do not increase. | "Customers table already contains 10000 records. Preserving existing records." Count remained 10,000. | PASS | Terminal execution output |
| 3 | **Random Selection** | Exactly 50 distinct opted-in customers selected without replacement; zero ineligible. | 50 distinct customer IDs selected; `ineligible_recipients = 0`. | PASS | Outbox SQL verification query |
| 4 | **Offline Preparation** | 50 PENDING requests created in outbox; repeat preparation adds no duplicates. | 50 PENDING requests staged; second run preserved identical 50 customer IDs. | PASS | `prepare-campaign.php` idempotency check |
| 5 | **Queue Publishing** | 50 QUEUED rows in outbox with SQS message IDs; repeat publication does not resend. | 50 rows transitioned to QUEUED with valid SQS UUIDs; repeat run reported 0 PENDING. | PASS | `enqueue.php` output and MySQL inspection |
| 6 | **Queue Processing** | 50 SENT outcomes with promo code; queue emptied; no unselected recipients sent. | Exactly 50 SENT outcomes in `sent_marketing_emails` with MIME Message-IDs; main queue backlog = 0. | PASS | `sent_marketing_emails` table and SQS attributes |
| 7 | **Sequential Duplicate** | Resubmitted completed pair results in `duplicate_skipped` without another SMTP send. | Worker logged `duplicate_skipped`, acknowledged and deleted SQS message, 0 emails sent. | PASS | `test_duplicate` trace & `events.jsonl` |
| 8 | **Controlled Failure** | Malformed input retries 3 times, moves to DLQ, and triggers alarm condition. | Message received 4 times, automatically routed to DLQ by redrive policy; DLQ receive verified. | PASS | SQS DLQ inspection & CloudWatch alarm |
| 9 | **Recovery & Consent** | Corrected diagnostic request for opted-out customer stored as SKIPPED; DLQ cleaned. | Customer `CUST-08001` recorded as `SKIPPED`, deleted from queue, DLQ message purged; backlog = 0. | PASS | `CAMP-RECOVERY` history row & DLQ clean |

---

## 10. Reflection

In this laboratory activity, decoupled architecture and object-oriented principles demonstrated how local enterprise systems and cloud services cooperate reliably. Local MySQL handled relational persistence and data privacy—ensuring customer PII remained strictly local—while Amazon SQS acted as a resilient buffer separating high-volume campaign preparation from rate-limited SMTP dispatching.

Implementing constructor injection reinforced the importance of loose coupling and testability: classes such as `MarketingProcessor` and `QueuePublisher` received explicit dependencies (`PDO`, `SqsClient`, `ActivityLogger`), avoiding brittle global state. Working with Faker and prepared statements highlighted real-world data generation dynamics, where random sampling without replacement and transactional outbox staging guaranteed idempotency even under repeated executions.

Differentiating message arrival, processing, acknowledgment, and failure was made transparent through structured logging and CloudWatch metrics. Message arrival (`received`) signifies queue delivery, whereas true success requires database persistence before deletion (`deleted`). Investigating the controlled failure demonstrated the power of Dead-Letter Queues and CloudWatch alarms: poisoning messages cannot block the pipeline, and operational evidence enables root cause diagnosis. To improve production resilience, I would introduce client-side JSON Schema validation at the publisher level to reject malformed payloads before enqueueing.

---

## 11. Screenshot Evidence Index

All high-resolution evidence screenshots are archived in the repository's `evidence/` directory:
- `evidence/01_sqs_queues_filtered.png`: AWS SQS console displaying both `MarketingEmailQueue-andrik-canlas` and `MarketingEmailDLQ-andrik-canlas`.
- `evidence/02_main_queue_details.png`: Main Queue configuration showing Visibility Timeout (180s), Message Retention (4 days), and Receive Wait Time (20s).
- `evidence/02_main_queue_dlq_tab.png`: Dead-Letter Queue settings showing redrive target ARN and Maximum Receives = 3.
- `evidence/03_dlq_details.png`: DLQ configuration showing 14 days message retention.
- `evidence/04_iam_policy.png`: Least-privilege IAM policy `MarketingLabSQS-andrik-canlas` permissions.
- `evidence/05_iam_user.png`: IAM user `marketing-lab-andrik-canlas` with attached policy.
- `evidence/06_cloudwatch_dashboard_final.png`: CloudWatch Dashboard `MarketingLab-andrik-canlas` displaying the 50 sent and 50 deleted operation metrics, backlog curve, message age, and DLQ status.
- `evidence/07_dlq_alarm.png`: CloudWatch Alarm `MarketingLab-DLQ-andrik-canlas` configuration and threshold.
- `evidence/08_backlog_alarm.png`: CloudWatch Alarm `MarketingLab-Backlog-andrik-canlas` showing threshold breach during stopped state and return to OK.
