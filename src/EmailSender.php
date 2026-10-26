<?php
namespace Lab;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class EmailSender
{
    public function __construct(
        private string $host,
        private int $port,
        private ?string $username = null,
        private ?string $password = null,
        private string $fromAddress = 'marketing@example.com',
        private string $fromName = 'Marketing Department'
    ) {}

    public function send(array $customer, array $job): string
    {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $this->host;
        $mail->Port       = $this->port;
        $mail->SMTPAuth   = !empty($this->username);
        $mail->Username   = $this->username ?? '';
        $mail->Password   = $this->password ?? '';
        
        if (in_array($this->host, ['127.0.0.1', 'localhost'], true)) {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        } else {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($this->fromAddress, $this->fromName);

        // TODO E1: Add the customer's email and name as the recipient
        $mail->addAddress($customer['email'], $customer['full_name']);

        // TODO E2: Set the campaign subject and a personalized plain-text body
        // that clearly displays the persisted promoCode from the job.
        $mail->Subject = $job['subject'];
        $mail->isHTML(false);
        $mail->Body = "Hello {$customer['full_name']},\n\n"
            . "{$job['body']}\n\n"
            . "Your Exclusive Promo Code: {$job['promoCode']}\n\n"
            . "Thank you for subscribing!\n";

        // Send email (throws Exception on failure)
        $mail->send();

        // Return MIME Message-ID
        return $mail->getLastMessageID() ?: ('<' . bin2hex(random_bytes(16)) . '@mailtrap>');
    }
}
