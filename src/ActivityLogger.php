<?php
namespace Lab;

final class ActivityLogger
{
    private string $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath ?: dirname(__DIR__) . '/logs/events.jsonl';
        $logDir = dirname($this->logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
    }

    public function record(string $event, array $data = []): void
    {
        $payload = array_merge([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'event'     => $event,
        ], $data);

        // Sanitize: do not store names, email addresses, credentials, or message body content
        unset(
            $payload['name'],
            $payload['full_name'],
            $payload['email'],
            $payload['recipient_email'],
            $payload['body'],
            $payload['password'],
            $payload['secret'],
            $payload['access_key']
        );

        file_put_contents(
            $this->logPath,
            json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }
}
