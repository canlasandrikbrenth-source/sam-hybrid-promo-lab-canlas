<?php
require __DIR__ . '/config.php';

$campaignId = $argv[1] ?? 'CAMP-001';
$promoCode = $argv[2] ?? 'PROMO15';

$db = (new Lab\Database())->connect();
$preparer = new Lab\CampaignPreparer($db);
$preparer->prepare($campaignId, $promoCode);
