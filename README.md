# Hybrid Promotional Campaign Laboratory

**Course:** Systems Administration and Maintenance  
**Student:** Andrik Canlas  
**Repository:** https://github.com/canlasandrikbrenth-source/sam-hybrid-promo-lab-canlas.git  
**Stack:** PHP 8.2+ (OOP), MySQL 8.0 / MariaDB, Composer, FakerPHP, PHPMailer, Amazon SQS, CloudWatch, Mailtrap

---

## 1. Overview & Architecture

This application implements a decoupled hybrid promotional email pipeline that prepares, queues, processes, and monitors marketing email campaigns.

### Architecture Workflow
1. **Local Population Generation:** 10,000 fictional customer records are seeded into MySQL using Faker (8,000 opted in, 2,000 opted out).
2. **Campaign Preparation:** 50 distinct opted-in customers are selected at random without replacement and staged as `PENDING` records in `marketing_outbox`.
3. **Queue Publishing:** `enqueue.php` reads pending records, publishes them as structured JSON payloads to Amazon Simple Queue Service (SQS) Standard Queue, records SQS Message IDs, and marks them `QUEUED`.
4. **Queue Worker & Processor:** `worker.php` receives messages from Amazon SQS, verifies consent against the local database, ensures idempotency, dispatches emails through SMTP (Mailtrap sandbox), saves outcome as `SENT` or `SKIPPED`, and deletes acknowledged messages from SQS.
5. **Dead-Letter Queue (DLQ) & Fault Tolerance:** Messages that fail processing are left unacknowledged; once they exceed `maxReceiveCount = 3`, SQS automatically moves them to the DLQ, triggering CloudWatch alarms.

---

## 2. Prerequisites & Environment Setup

- PHP 8.2 or higher with `pdo_mysql`, `openssl`, and `mbstring` extensions enabled.
- MySQL 8.0+ / MariaDB.
- Composer.
- AWS CLI v2 configured with IAM application user.

### Environment Variables
Configure the following environment variables (e.g. in PowerShell):

```powershell
$env:DB_HOST = "127.0.0.1"
$env:DB_PORT = "3306"
$env:DB_NAME = "marketing_lab"
$env:DB_USER = "root"
$env:DB_PASSWORD = ""

$env:AWS_PROFILE = "marketing-lab"
$env:AWS_REGION = "ap-southeast-2"
$env:SQS_QUEUE_URL = "https://sqs.REGION.amazonaws.com/ACCOUNT_ID/MarketingEmailQueue-IDENTIFIER"

$env:MAILTRAP_HOST = "sandbox.smtp.mailtrap.io"
$env:MAILTRAP_PORT = "2525"
$env:MAILTRAP_USERNAME = "YOUR_MAILTRAP_USERNAME"
$env:MAILTRAP_PASSWORD = "YOUR_MAILTRAP_PASSWORD"
$env:MAIL_FROM_ADDRESS = "marketing@example.com"
$env:MAILTRAP_DELAY_SECONDS = "0"
```

---

## 3. Installation & Database Setup

1. Install Composer dependencies:
   ```bash
   composer install
   composer dump-autoload
   ```

2. Initialize database schema:
   ```bash
   php init-db.php
   ```

3. Seed 10,000 fictional customers:
   ```bash
   php seed-customers.php
   ```

4. Verify database state:
   ```bash
   php inspect.php
   ```

---

## 4. Running the Campaign Pipeline

1. **Prepare a Campaign (50 Recipients):**
   ```bash
   php prepare-campaign.php CAMP-001 PROMO15
   ```

2. **Publish Messages to SQS:**
   ```bash
   php enqueue.php
   ```

3. **Process Queued Messages:**
   ```bash
   php worker.php
   ```

4. **Inspect Outcomes:**
   ```bash
   php inspect.php
   ```

---

## 5. Security & Credentials
- All sensitive credentials (database passwords, AWS secrets, SMTP credentials) are injected via environment variables and never committed to source control.
- Customer personal data (names, emails) remain strictly on the local MySQL database and are never included in SQS payloads or operational logs.
- IAM permissions follow the principle of least privilege, scoped strictly to the specific queue and DLQ ARNs.
