<?php

declare(strict_types=1);

namespace openvk\Web\Models\Repositories;

use Chandler\Database\DatabaseConnection;
use Nette\Database\Table\ActiveRow;
use openvk\Web\Models\Entities\AppActivity;
use openvk\Web\Models\Entities\Application;
use openvk\Web\Models\Entities\User;

class Applications
{
    private $context;
    private $apps;
    private $appRels;

    private static $cache = [];

    public function __construct()
    {
        $this->context = DatabaseConnection::i()->getContext();
        $this->apps    = $this->context->table("apps");
        $this->appRels = $this->context->table("app_users");
    }

    private function toApp(?ActiveRow $ar): ?Application
    {
        return is_null($ar) ? null : new Application($ar);
    }

    public function get(int $id): ?Application
    {
        return self::$cache[$id] ??= $this->toApp($this->apps->get($id));
    }

    public function getList(int $page = 1, ?int $perPage = null): \Traversable
    {
        $perPage ??= OPENVK_DEFAULT_PER_PAGE;
        $apps    = $this->apps->where(["enabled" => 1, "deleted" => 0])->page($page, $perPage);
        foreach ($apps as $app) {
            yield new Application($app);
        }
    }

    public function getListCount(): int
    {
        return sizeof($this->apps->where(["enabled" => 1, "deleted" => 0]));
    }

    public function getByType(int $type, int $page = 1, ?int $perPage = null): \Traversable
    {
        $perPage ??= OPENVK_DEFAULT_PER_PAGE;
        $apps    = $this->apps->where([
            "enabled" => 1,
            "deleted" => 0,
            "type"    => $type,
        ])->order("id DESC")->page($page, $perPage);

        foreach ($apps as $app) {
            yield new Application($app);
        }
    }

    public function getByTypeCount(int $type): int
    {
        return sizeof($this->apps->where([
            "enabled" => 1,
            "deleted" => 0,
            "type"    => $type,
        ]));
    }

    public function getByOwner(User $owner, int $page = 1, ?int $perPage = null): \Traversable
    {
        $perPage ??= OPENVK_DEFAULT_PER_PAGE;
        $apps    = $this->apps->where(["owner" => $owner->getId(), "deleted" => 0])->page($page, $perPage);
        foreach ($apps as $app) {
            yield new Application($app);
        }
    }

    public function getOwnCount(User $owner): int
    {
        return sizeof($this->apps->where(["owner" => $owner->getId(), "deleted" => 0]));
    }

    public function getInstalled(User $user, int $page = 1, ?int $perPage = null): \Traversable
    {
        $perPage ??= OPENVK_DEFAULT_PER_PAGE;
        $apps    = $this->appRels->where(["user" => $user->getId(), "deleted" => 0])->page($page, $perPage);
        foreach ($apps as $appRel) {
            yield $this->get($appRel->app);
        }
    }

    public function getInstalledCount(User $user): int
    {
        return sizeof($this->appRels->where(["user" => $user->getId(), "deleted" => 0]));
    }

    public function getPopular(?int $type = null, int $limit = 5): array
    {
        $params = [];
        $typeSql = "";
        if ($type !== null) {
            $typeSql = "AND apps.type = ?";
            $params[] = $type;
        }
        $params[] = $limit;

        $rows = $this->context->query(
            "SELECT apps.id, COUNT(app_users.user) AS users_count
             FROM apps
             LEFT JOIN app_users ON app_users.app = apps.id AND app_users.deleted = 0
             WHERE apps.enabled = 1 AND apps.deleted = 0 $typeSql
             GROUP BY apps.id
             ORDER BY users_count DESC, apps.id DESC
             LIMIT ?",
            ...$params
        );

        $result = [];
        foreach ($rows as $row) {
            $app = $this->get((int) $row->id);
            if ($app) {
                $result[] = $app;
            }
        }

        return $result;
    }

    public function getNew(?int $type = null, int $limit = 5): array
    {
        $query = $this->apps->where(["enabled" => 1, "deleted" => 0])->order("id DESC")->limit($limit);
        if ($type !== null) {
            $query->where("type", $type);
        }

        $result = [];
        foreach ($query as $app) {
            $result[] = new Application($app);
        }

        return $result;
    }

    public function getFriendsActivity(User $user, int $limit = 10): array
    {
        $friendIds = [];
        foreach ($user->getFriends(1, 3000) as $friend) {
            $friendIds[] = $friend->getId();
        }

        if (sizeof($friendIds) === 0) {
            return [];
        }

        $rows = $this->context->table("app_activity")
            ->where("user", $friendIds)
            ->order("created DESC")
            ->limit($limit);

        $result = [];
        foreach ($rows as $row) {
            $app = $this->get((int) $row->app);
            if (!$app || $app->isDeleted() || !$app->isEnabled()) {
                continue;
            }

            $result[] = new AppActivity(
                (int) $row->user,
                (int) $row->app,
                (int) $row->kind,
                (int) $row->created
            );
        }

        return $result;
    }

    public function find(string $query = "", array $params = [], array $order = ['type' => 'id', 'invert' => false]): Util\EntityStream
    {
        $query = "%$query%";
        $result = $this->apps->where("CONCAT_WS(' ', name, description) LIKE ?", $query)->where(["enabled" => 1, "deleted" => 0]);
        $order_str = 'id';

        switch ($order['type']) {
            case 'id':
                $order_str = 'id ' . ($order['invert'] ? 'ASC' : 'DESC');
                break;
        }

        if ($order_str) {
            $result->order($order_str);
        }

        return new Util\EntityStream("Application", $result);
    }
}
