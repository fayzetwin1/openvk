<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use openvk\Web\Models\Repositories\Applications;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Util\DateTime;

class AppActivity
{
    private int $userId;
    private int $appId;
    private int $kind;
    private int $created;

    public function __construct(int $userId, int $appId, int $kind, int $created)
    {
        $this->userId  = $userId;
        $this->appId   = $appId;
        $this->kind    = $kind;
        $this->created = $created;
    }

    public function getUser(): ?User
    {
        return (new Users())->get($this->userId);
    }

    public function getApp(): ?Application
    {
        return (new Applications())->get($this->appId);
    }

    public function getKind(): int
    {
        return $this->kind;
    }

    public function isInstall(): bool
    {
        return $this->kind === Application::ACTIVITY_INSTALL;
    }

    public function isLaunch(): bool
    {
        return $this->kind === Application::ACTIVITY_LAUNCH;
    }

    public function getTime(): DateTime
    {
        return new DateTime($this->created);
    }
}
