<?php
require __DIR__ . '/config.php';

$publisher = new Lab\QueuePublisher(
    (new Lab\Database())->connect(),
    Lab\Settings::queue(),
    Lab\Settings::required('SQS_QUEUE_URL'),
    new Lab\ActivityLogger()
);
$publisher->publishPending();
