<?php

declare(strict_types=1);

namespace openvk\Web\Models\Entities;

use Chandler\Database\DatabaseConnection;
use Nette\Utils\Image;
use Nette\Utils\UnknownImageFileException;
use openvk\Web\Models\Repositories\Notes;
use openvk\Web\Models\Repositories\Users;
use openvk\Web\Models\RowModel;

class Application extends RowModel
{
    protected $tableName = "apps";

    public const TYPE_APP  = 0;
    public const TYPE_GAME = 1;

    public const RUNNER_HTML  = 0;
    public const RUNNER_FLASH = 1;

    public const ACTIVITY_INSTALL = 1;
    public const ACTIVITY_LAUNCH  = 2;

    public const SWF_MAX_BYTES = 20971520; # 20 MB

    public const PERMS = [
        "notify",
        "friends",
        "photos",
        "audio",
        "video",
        "stories",
        "pages",
        "status",
        "notes",
        "messages",
        "wall",
        "ads",
        "docs",
        "groups",
        "notifications",
        "stats",
        "email",
        "market",
    ];

    private function getAvatarsDir(): string
    {
        $uploadSettings = OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"];
        if ($uploadSettings["mode"] === "server" && $uploadSettings["server"]["kind"] === "cdn") {
            return $uploadSettings["server"]["directory"];
        } else {
            return OPENVK_ROOT . "/storage/";
        }
    }

    public function getId(): int
    {
        return $this->getRecord()->id;
    }

    public function getOwner(): User
    {
        return (new Users())->get($this->getRecord()->owner);
    }

    public function getName(): string
    {
        return $this->getRecord()->name;
    }

    public function getDescription(): string
    {
        return $this->getRecord()->description;
    }

    public function getAvatarUrl(): string
    {
        $serverUrl = ovk_scheme(true) . $_SERVER["HTTP_HOST"];
        if (is_null($this->getRecord()->avatar_hash)) {
            return "$serverUrl/assets/packages/static/openvk/img/camera_200.png";
        }

        $hash = $this->getRecord()->avatar_hash;
        switch (OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["mode"]) {
            default:
            case "default":
            case "basic":
                return "$serverUrl/blob_" . substr($hash, 0, 2) . "/$hash" . "_app_avatar.png";
            case "accelerated":
                return "$serverUrl/openvk-datastore/$hash" . "_app_avatar.png";
            case "server":
                $settings = (object) OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["server"];
                return (
                    ($settings->protocol ?? ovk_scheme()) .
                    "://" . $settings->host .
                    $settings->path .
                    substr($hash, 0, 2) . "/$hash" . "_app_avatar.png"
                );
        }
    }

    public function getNote(): ?Note
    {
        if (!$this->getRecord()->news) {
            return null;
        }

        return (new Notes())->get($this->getRecord()->news);
    }

    public function getNoteLink(): string
    {
        $note = $this->getNote();
        if (!$note) {
            return "";
        }

        return ovk_scheme(true) . $_SERVER["HTTP_HOST"] . "/note" . $note->getPrettyId();
    }

    public function getBalance(): float
    {
        return $this->getRecord()->coins;
    }

    public function getURL(): string
    {
        return (string) ($this->getRecord()->address ?? "");
    }

    public function getOrigin(): string
    {
        $url = $this->getURL();
        if ($url === "") {
            return ovk_scheme(true) . ($_SERVER["HTTP_HOST"] ?? "127.0.0.1");
        }

        $parsed = parse_url($url);

        return (
            ($parsed["scheme"] ?? "https") . "://"
            . ($parsed["host"] ?? "127.0.0.1") . ":"
            . ($parsed["port"] ?? "443")
        );
    }

    public function getRunner(): int
    {
        return (int) ($this->getRecord()->runner ?? self::RUNNER_HTML);
    }

    public function setRunner(int $runner): void
    {
        $this->stateChanges("runner", $runner === self::RUNNER_FLASH ? self::RUNNER_FLASH : self::RUNNER_HTML);
    }

    public function isFlash(): bool
    {
        return $this->getRunner() === self::RUNNER_FLASH;
    }

    public function getSecret(): string
    {
        if (isset($this->changes["secret"])) {
            return (string) $this->changes["secret"];
        }

        if (is_null($this->getRecord())) {
            return "";
        }

        return (string) ($this->getRecord()->secret ?? "");
    }

    public function ensureSecret(): void
    {
        if ($this->getSecret() !== "") {
            // #region agent log
            @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H1", "location" => "Application.php:ensureSecret", "message" => "ensureSecret noop existing", "data" => ["hadSecret" => true, "inChanges" => isset($this->changes["secret"])], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
            // #endregion
            return;
        }

        $this->stateChanges("secret", bin2hex(random_bytes(16)));
        // #region agent log
        @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H1", "location" => "Application.php:ensureSecret", "message" => "ensureSecret generated into changes", "data" => ["generated" => true], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion
    }

    public function getSwfHash(): ?string
    {
        if (isset($this->changes["swf_hash"])) {
            $hash = $this->changes["swf_hash"];

            return $hash === null || $hash === "" ? null : (string) $hash;
        }

        if (is_null($this->getRecord())) {
            return null;
        }

        $hash = $this->getRecord()->swf_hash ?? null;

        return $hash === null || $hash === "" ? null : (string) $hash;
    }

    public function getSwfUrl(): ?string
    {
        $hash = $this->getSwfHash();
        if ($hash === null) {
            return null;
        }

        $serverUrl = ovk_scheme(true) . $_SERVER["HTTP_HOST"];
        switch (OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["mode"]) {
            default:
            case "default":
            case "basic":
                return "$serverUrl/blob_" . substr($hash, 0, 2) . "/$hash" . "_app.swf";
            case "accelerated":
                return "$serverUrl/openvk-datastore/$hash" . "_app.swf";
            case "server":
                $settings = (object) OPENVK_ROOT_CONF["openvk"]["preferences"]["uploads"]["server"];
                return (
                    ($settings->protocol ?? ovk_scheme()) .
                    "://" . $settings->host .
                    $settings->path .
                    substr($hash, 0, 2) . "/$hash" . "_app.swf"
                );
        }
    }

    public function setSwf(array $file): int
    {
        if ($file["error"] !== UPLOAD_ERR_OK) {
            return -1;
        }

        if (($file["size"] ?? 0) <= 0 || ($file["size"] ?? 0) > self::SWF_MAX_BYTES) {
            return -2;
        }

        $name = strtolower((string) ($file["name"] ?? ""));
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        if ($ext !== "swf") {
            return -3;
        }

        $tmp = (string) ($file["tmp_name"] ?? "");
        if ($tmp === "" || !is_uploaded_file($tmp)) {
            return -1;
        }

        $header = (string) file_get_contents($tmp, false, null, 0, 3);
        if (!in_array($header, ["FWS", "CWS", "ZWS"], true)) {
            return -4;
        }

        $hash = hash_file("adler32", $tmp);
        $dir  = $this->getAvatarsDir() . substr($hash, 0, 2);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            return -5;
        }

        $dest = $dir . "/$hash" . "_app.swf";
        if (!move_uploaded_file($tmp, $dest) && !rename($tmp, $dest)) {
            if (!copy($tmp, $dest)) {
                // #region agent log
                @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H2", "location" => "Application.php:setSwf", "message" => "setSwf write failed", "data" => ["hashLen" => strlen((string) $hash), "dest" => basename($dest)], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
                // #endregion
                return -5;
            }
        }

        $this->stateChanges("swf_hash", $hash);
        // #region agent log
        @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H2", "location" => "Application.php:setSwf", "message" => "setSwf ok", "data" => ["hashLen" => strlen((string) $hash), "hash" => $hash, "exists" => is_file($dest), "size" => @filesize($dest)], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
        // #endregion

        return 0;
    }

    public function makeAuthKey(User $viewer): string
    {
        return md5($this->getId() . "_" . $viewer->getId() . "_" . $this->getSecret());
    }

    public function getPermissionMask(User $user): int
    {
        $installInfo = $this->getInstallationEntry($user);
        if (!$installInfo) {
            return 0;
        }

        return (int) ($installInfo["access"] ?? 0);
    }

    /**
     * Classic VK Flash app flashVars.
     *
     * @return array<string, string|int>
     */
    public function getFlashVars(User $viewer, string $lang = "3"): array
    {
        $this->ensureSecret();
        $sid = bin2hex(random_bytes(16));

        return [
            "api_id"       => $this->getId(),
            "viewer_id"    => $viewer->getId(),
            "user_id"      => $viewer->getId(),
            "group_id"     => 0,
            "is_app_user"  => 1,
            "viewer_type"  => 0,
            "auth_key"     => $this->makeAuthKey($viewer),
            "language"     => $lang,
            "api_url"      => ovk_scheme(true) . ($_SERVER["HTTP_HOST"] ?? "127.0.0.1") . "/method/",
            "api_settings" => $this->getPermissionMask($viewer),
            "sid"          => $sid,
            "secret"       => substr($this->getSecret(), 0, 10),
            "access_token" => "",
            "lc_name"      => $this->getName(),
        ];
    }

    public function getUsersCount(): int
    {
        $cx = DatabaseConnection::i()->getContext();
        return sizeof($cx->table("app_users")->where([
            "app"     => $this->getId(),
            "deleted" => 0,
        ]));
    }

    public function getType(): int
    {
        return (int) ($this->getRecord()->type ?? self::TYPE_GAME);
    }

    public function setType(int $type): void
    {
        $this->stateChanges("type", $type === self::TYPE_APP ? self::TYPE_APP : self::TYPE_GAME);
    }

    public function isGame(): bool
    {
        return $this->getType() === self::TYPE_GAME;
    }

    public function logActivity(User $user, int $kind): void
    {
        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_activity")->insert([
            "user"    => $user->getId(),
            "app"     => $this->getId(),
            "kind"    => $kind,
            "created" => time(),
        ]);
    }

    public function getInstallationEntry(User $user): ?array
    {
        $cx    = DatabaseConnection::i()->getContext();
        $entry = $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->fetch();

        if (!$entry) {
            return null;
        }

        return $entry->toArray();
    }

    public function getPermissions(User $user): array
    {
        $permMask    = 0;
        $installInfo = $this->getInstallationEntry($user);
        if (!$installInfo) {
            $this->install($user);
        } else {
            $permMask = $installInfo["access"];
        }

        $res = [];
        for ($i = 0; $i < sizeof(self::PERMS); $i++) {
            $checkVal = 1 << $i;
            if (($permMask & $checkVal) > 0) {
                $res[] = self::PERMS[$i];
            }
        }

        return $res;
    }

    public function isInstalledBy(User $user): bool
    {
        return !is_null($this->getInstallationEntry($user));
    }

    public function setNoteLink(?string $link): bool
    {
        if (!$link) {
            $this->stateChanges("news", null);

            return true;
        }

        preg_match("%note([0-9]+)_([0-9]+)$%", $link, $matches);
        if (sizeof($matches) != 3) {
            return false;
        }

        $owner = is_null($this->getRecord()) ? $this->changes["owner"] : $this->getRecord()->owner;
        [, $ownerId, $vid] = $matches;
        if ($ownerId != $owner) {
            return false;
        }

        $note = (new Notes())->getNoteById((int) $ownerId, (int) $vid);
        if (!$note) {
            return false;
        }

        $this->stateChanges("news", $note->getId());

        return true;
    }

    public function setAvatar(array $file): int
    {
        if ($file["error"] !== UPLOAD_ERR_OK) {
            return -1;
        }

        try {
            $image = Image::fromFile($file["tmp_name"]);
        } catch (UnknownImageFileException $e) {
            return -2;
        }

        $hash = hash_file("adler32", $file["tmp_name"]);
        if (!is_dir($this->getAvatarsDir() . substr($hash, 0, 2))) {
            if (!mkdir($this->getAvatarsDir() . substr($hash, 0, 2))) {
                return -3;
            }
        }

        $image->resize(140, 140, Image::STRETCH);
        $image->save($this->getAvatarsDir() . substr($hash, 0, 2) . "/$hash" . "_app_avatar.png");

        $this->stateChanges("avatar_hash", $hash);

        return 0;
    }

    public function setPermission(User $user, string $perm, bool $enabled): bool
    {
        $permMask    = 0;
        $installInfo = $this->getInstallationEntry($user);
        if (!$installInfo) {
            $this->install($user);
        } else {
            $permMask = $installInfo["access"];
        }

        $index = array_search($perm, self::PERMS);
        if ($index === false) {
            return false;
        }

        $permVal  = 1 << $index;
        $permMask = $enabled ? ($permMask | $permVal) : ($permMask ^ $permVal);

        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->update([
            "access" => $permMask,
        ]);

        return true;
    }

    public function isEnabled(): bool
    {
        return (bool) $this->getRecord()->enabled;
    }

    public function enable(): void
    {
        $this->stateChanges("enabled", 1);
        $this->save();
    }

    public function disable(): void
    {
        $this->stateChanges("enabled", 0);
        $this->save();
    }

    public function install(User $user): void
    {
        if (!$this->getInstallationEntry($user)) {
            $cx = DatabaseConnection::i()->getContext();
            $cx->table("app_users")->insert([
                "app"  => $this->getId(),
                "user" => $user->getId(),
            ]);
            $this->logActivity($user, self::ACTIVITY_INSTALL);
        }
    }

    public function uninstall(User $user): void
    {
        $cx = DatabaseConnection::i()->getContext();
        $cx->table("app_users")->where([
            "app"  => $this->getId(),
            "user" => $user->getId(),
        ])->delete();
    }

    public function addCoins(float $coins): float
    {
        $res = $this->getBalance() + $coins;
        $this->stateChanges("coins", $res);
        $this->save();

        return $res;
    }

    public function withdrawCoins(): void
    {
        $balance = $this->getBalance();
        $tax     = ($balance / 100) * OPENVK_ROOT_CONF["openvk"]["preferences"]["apps"]["withdrawTax"];

        $owner = $this->getOwner();
        $owner->setCoins($owner->getCoins() + ($balance - $tax));
        $this->setCoins(0.0);
        $this->save();
        $owner->save();
    }

    public function delete(bool $softly = true): void
    {
        $cx = DatabaseConnection::i()->getContext();
        $app_users = $cx->table("app_users")->where("app", $this->getId());

        if ($softly) {
            $app_users->update(["deleted" => 1]);
        } else {
            $app_users->delete();
        }

        parent::delete($softly);
    }

    public function getPublicationTime(): string
    {
        return tr("recently");
    }
}
