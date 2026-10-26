<?php
require_once __DIR__ . '/vendor/autoload.php';

// Set default environment variables if not already provided
if (!getenv('DB_HOST')) putenv('DB_HOST=127.0.0.1');
if (!getenv('DB_PORT')) putenv('DB_PORT=3306');
if (!getenv('DB_NAME')) putenv('DB_NAME=marketing_lab');
if (!getenv('DB_USER')) putenv('DB_USER=root');
if (getenv('DB_PASSWORD') === false) putenv('DB_PASSWORD=');

if (!getenv('AWS_PROFILE')) putenv('AWS_PROFILE=marketing-lab');
if (!getenv('AWS_REGION')) putenv('AWS_REGION=ap-southeast-2');
if (!getenv('SQS_QUEUE_URL')) putenv('SQS_QUEUE_URL=https://sqs.ap-southeast-2.amazonaws.com/408852558600/MarketingEmailQueue-andrik-canlas');

if (!getenv('MAIL_FROM_ADDRESS')) putenv('MAIL_FROM_ADDRESS=marketing@example.com');
if (!getenv('MAILTRAP_HOST')) putenv('MAILTRAP_HOST=127.0.0.1');
if (!getenv('MAILTRAP_PORT')) putenv('MAILTRAP_PORT=2525');
if (!getenv('MAILTRAP_USERNAME')) putenv('MAILTRAP_USERNAME=local-mailtrap-user');
if (!getenv('MAILTRAP_PASSWORD')) putenv('MAILTRAP_PASSWORD=local-mailtrap-pass');
if (!getenv('MAILTRAP_DELAY_SECONDS')) putenv('MAILTRAP_DELAY_SECONDS=0');
