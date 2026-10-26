<?php
namespace Lab;

use Aws\Sqs\SqsClient;
use RuntimeException;

final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            return $default;
        }
        return $val;
    }

    public static function required(string $key): string
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
        return $val;
    }

    public static function queue(): SqsClient
    {
        $config = [
            'version' => 'latest',
            'region'  => self::get('AWS_REGION', 'ap-southeast-2'),
        ];

        $profile = self::get('AWS_PROFILE');
        if ($profile) {
            $config['profile'] = $profile;
        }

        return new SqsClient($config);
    }
}
