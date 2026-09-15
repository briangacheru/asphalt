<?php

namespace App\Services;

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

/**
 * Sends browser push notifications via the Web Push protocol (VAPID).
 * A thin wrapper around minishlink/web-push, used as a second channel
 * alongside the existing reminder emails — see the notifyUser() call sites
 * in EmailService for the reminder types that also push.
 *
 * No-ops quietly whenever VAPID keys aren't configured (see .env / Config.php)
 * so the app works fine without push set up — email keeps working either way.
 */
class PushService
{
    public static function isConfigured(): bool
    {
        return defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY !== ''
            && defined('VAPID_PRIVATE_KEY') && VAPID_PRIVATE_KEY !== '';
    }

    /**
     * Push a notification to every device a user has subscribed on.
     * Silently prunes subscriptions the push service reports as gone.
     */
    public static function notifyUser(\PDO $pdo, int $userId, string $title, string $body, string $url = '/'): void
    {
        if (!self::isConfigured()) {
            return;
        }

        $subscriptions = PushSubscriptionService::forUser($pdo, $userId);
        if (empty($subscriptions)) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => VAPID_SUBJECT,
                    'publicKey' => VAPID_PUBLIC_KEY,
                    'privateKey' => VAPID_PRIVATE_KEY,
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('PushService: failed to init WebPush client - ' . $e->getMessage());
            return;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'icon' => (defined('APP_URL') ? rtrim(APP_URL, '/') : '') . '/assets/img/pwa/icon-192.png',
        ]);

        foreach ($subscriptions as $row) {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'publicKey' => $row['p256dh'],
                'authToken' => $row['auth_token'],
                'contentEncoding' => 'aes128gcm',
            ]);

            try {
                $webPush->queueNotification($subscription, $payload);
            } catch (\Throwable $e) {
                error_log('PushService: failed to queue notification - ' . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscriptionService::deleteByEndpoint($pdo, $report->getEndpoint());
            }
        }
    }
}
