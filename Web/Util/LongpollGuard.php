<?php

declare(strict_types=1);

namespace openvk\Web\Util;

class LongpollGuard
{
    public static function acquire(int $userId, int $maxConcurrentPerUser = 1, int $maxConcurrentPerIp = 2): bool
    {
        $ip = defined("CONNECTING_IP")
            ? (string) CONNECTING_IP
            : (string) ($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0");

        if (!CostlyOpGuard::acquire("longpoll_ip_" . $ip, $maxConcurrentPerIp)) {
            return false;
        }

        if (!CostlyOpGuard::acquire("longpoll_user_" . $userId, $maxConcurrentPerUser)) {
            CostlyOpGuard::release("longpoll_ip_" . $ip);

            return false;
        }

        return true;
    }

    public static function release(int $userId): void
    {
        $ip = defined("CONNECTING_IP")
            ? (string) CONNECTING_IP
            : (string) ($_SERVER["REMOTE_ADDR"] ?? "0.0.0.0");

        CostlyOpGuard::release("longpoll_user_" . $userId);
        CostlyOpGuard::release("longpoll_ip_" . $ip);
    }
}
