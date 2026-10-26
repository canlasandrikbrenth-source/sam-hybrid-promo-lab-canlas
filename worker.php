<?php
require __DIR__ . '/config.php';

$db = (new Lab\Database())->connect();
$sqs = Lab\Settings::queue();
$queueUrl = Lab\Settings::required('SQS_QUEUE_URL');
$logger = new Lab\ActivityLogger();
$history = new Lab\EmailHistory($db);

$sender = new Lab\EmailSender(
    host: Lab\Settings::get('MAILTRAP_HOST', 'sandbox.smtp.mailtrap.io'),
    port: (int) Lab\Settings::get('MAILTRAP_PORT', '2525'),
    username: Lab\Settings::get('MAILTRAP_USERNAME'),
    password: Lab\Settings::get('MAILTRAP_PASSWORD'),
    fromAddress: Lab\Settings::get('MAIL_FROM_ADDRESS', 'marketing@example.com')
);

$sendDelay = (int) Lab\Settings::get('MAILTRAP_DELAY_SECONDS', '0');

$processor = new Lab\MarketingProcessor($db, $history, $sender, $logger, $sendDelay);
$worker = new Lab\QueueWorker($sqs, $queueUrl, $processor, $logger);

$maxIterations = isset($argv[1]) ? (int) $argv[1] : 0;
$worker->run($maxIterations);
