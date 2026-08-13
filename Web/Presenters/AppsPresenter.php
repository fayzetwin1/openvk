<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Entities\Application;
use openvk\Web\Models\Repositories\Applications;

final class AppsPresenter extends OpenVKPresenter
{
    private $apps;
    protected $presenterName = "apps";

    public function __construct(Applications $apps)
    {
        $this->apps = $apps;

        parent::__construct();
    }

    public function renderPlay(int $app): void
    {
        $this->assertUserLoggedIn();

        // #region agent log
        $__dbg = function (string $msg, array $data = [], string $hid = "H5") {
            @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => $hid, "location" => "AppsPresenter.php:renderPlay", "message" => $msg, "data" => $data, "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
        };
        $__dbg("renderPlay enter", ["appParam" => $app], "H5");
        // #endregion

        try {
        $app = $this->apps->get($app);
        if (!$app || !$app->isEnabled() || $app->isDeleted()) {
            $this->notFound();
        }

        if ($app->isFlash() && !$app->getSwfUrl()) {
            $this->flashFail("err", tr("app_err_not_found"), tr("app_err_swf_missing"));
        }

        $this->template->id     = $app->getId();
        $this->template->name   = $app->getName();
        $this->template->desc   = $app->getDescription();
        $this->template->origin = $app->getOrigin();
        $this->template->url    = $app->getURL();
        $this->template->owner  = $app->getOwner();
        $this->template->news   = $app->getNote();
        $this->template->perms  = $app->getPermissions($this->user->identity);
        $this->template->flash  = $app->isFlash();
        $this->template->swfUrl = $app->getSwfUrl();
        // #region agent log
        $__dbg("play flash branch entry", ["isFlash" => $app->isFlash(), "hasSwfUrl" => (bool) $app->getSwfUrl(), "secretViaGetterEmpty" => ($app->getSecret() === ""), "secretLen" => strlen($app->getSecret())], "H1");
        // #endregion

        // Persist app secret before flashVars/auth_key so it stays stable across reloads.
        if ($app->isFlash() && $app->getSecret() === "") {
            $app->ensureSecret();
            $app->save();
            // #region agent log
            $__dbg("saved empty secret branch", ["saved" => true, "secretLenAfter" => strlen($app->getSecret())], "H1");
            // #endregion
        }

        $this->template->flashVars = $app->isFlash()
            ? $app->getFlashVars($this->user->identity)
            : [];

        // #region agent log
        $__dbg("after getFlashVars", ["secretLen" => strlen($app->getSecret()), "flashVarsCount" => is_array($this->template->flashVars) ? count($this->template->flashVars) : 0], "H1");
        // #endregion

        $app->logActivity($this->user->identity, Application::ACTIVITY_LAUNCH);
        // #region agent log
        $__dbg("renderPlay presenter done", ["id" => $app->getId(), "isFlash" => $app->isFlash()], "H5");
        // #endregion
        } catch (\Throwable $e) {
            // #region agent log
            $__dbg("renderPlay exception", ["type" => get_class($e), "msg" => $e->getMessage(), "file" => $e->getFile(), "line" => $e->getLine()], "H5");
            // #endregion
            throw $e;
        }
    }

    public function renderUnInstall(): void
    {
        $this->assertUserLoggedIn();
        $this->assertNoCSRF();

        $app = $this->apps->get((int) $this->queryParam("app"));
        if (!$app || $app->isDeleted()) {
            $this->flashFail("err", tr("app_err_not_found"), tr("app_err_not_found_desc"));
        }

        $app->uninstall($this->user->identity);
        $this->flashFail("succ", tr("app_uninstalled"), tr("app_uninstalled_desc"));
    }

    public function renderEdit(): void
    {
        $this->assertUserLoggedIn();

        $app = null;
        if ($this->queryParam("act") !== "create") {
            if (empty($this->queryParam("app"))) {
                $this->flashFail("err", tr("app_err_not_found"), tr("app_err_not_found_desc"));
            }

            $app = $this->apps->get((int) $this->queryParam("app"));
            if (!$app || $app->isDeleted()) {
                $this->flashFail("err", tr("app_err_not_found"), tr("app_err_not_found_desc"));
            }

            if ($app->getOwner()->getId() != $this->user->identity->getId()) {
                $this->flashFail("err", tr("forbidden"), tr("app_err_forbidden_desc"));
            }
        }

        if ($_SERVER["REQUEST_METHOD"] === "POST") {
            if (!$app) {
                $app = new Application();
                $app->setOwner($this->user->id);
            } elseif ($this->postParam("delete_app") && $app->getOwner()->getId() === $this->user->id) {
                $app->delete();
                $this->redirect("/apps?act=dev");
                return;
            }

            $runner = $this->postParam("runner") === "flash" ? Application::RUNNER_FLASH : Application::RUNNER_HTML;
            $url    = trim((string) $this->postParam("url"));

            if ($runner === Application::RUNNER_HTML) {
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    $this->flashFail("err", tr("app_err_url"), tr("app_err_url_desc"));
                }
            } elseif ($url !== "" && !filter_var($url, FILTER_VALIDATE_URL)) {
                $this->flashFail("err", tr("app_err_url"), tr("app_err_url_desc"));
            }

            if (isset($_FILES["ava"]) && $_FILES["ava"]["size"] > 0) {
                if (($res = $app->setAvatar($_FILES["ava"])) !== 0) {
                    $this->flashFail("err", tr("app_err_ava"), tr("app_err_ava_desc", $res));
                }
            }

            if ($runner === Application::RUNNER_FLASH) {
                $app->ensureSecret();
                $hasUpload = isset($_FILES["swf"]) && ($_FILES["swf"]["size"] ?? 0) > 0;
                $hasExisting = (bool) $app->getSwfHash();
                // #region agent log
                @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H2", "location" => "AppsPresenter.php:renderEdit", "message" => "flash edit save path", "data" => ["hasUpload" => $hasUpload, "hasExisting" => $hasExisting, "uploadErr" => $_FILES["swf"]["error"] ?? null, "uploadSize" => $_FILES["swf"]["size"] ?? null], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
                // #endregion
                if ($hasUpload) {
                    if (($res = $app->setSwf($_FILES["swf"])) !== 0) {
                        // #region agent log
                        @file_put_contents(OPENVK_ROOT . "/storage/_debug_d99942.log", json_encode(["sessionId" => "d99942", "hypothesisId" => "H2", "location" => "AppsPresenter.php:renderEdit", "message" => "setSwf failed", "data" => ["res" => $res], "timestamp" => (int) (microtime(true) * 1000)]) . "\n", FILE_APPEND);
                        // #endregion
                        $this->flashFail("err", tr("app_err_swf"), tr("app_err_swf_desc", $res));
                    }
                } elseif (!$hasExisting) {
                    $this->flashFail("err", tr("app_err_swf"), tr("app_err_swf_required"));
                }
            }

            if (empty($this->postParam("note"))) {
                $app->setNoteLink(null);
            } else {
                if (!$app->setNoteLink($this->postParam("note"))) {
                    $this->flashFail("err", tr("app_err_note"), tr("app_err_note_desc"));
                }
            }

            $type = $this->postParam("type") === "app" ? Application::TYPE_APP : Application::TYPE_GAME;
            $app->setType($type);
            $app->setRunner($runner);
            $app->setName($this->postParam("name"));
            $app->setDescription($this->postParam("desc"));
            $app->setAddress($url !== "" ? $url : "https://example.invalid/");
            if ($this->postParam("enable") === "on") {
                $app->enable();
            } else {
                $app->disable();
            } # no need to save since enable/disable will call save() internally

            $this->redirect("/editapp?act=edit&app=" . $app->getId()); # will exit here
        }

        if (!is_null($app)) {
            $this->template->create = false;
            $this->template->id     = $app->getId();
            $this->template->name   = $app->getName();
            $this->template->desc   = $app->getDescription();
            $this->template->coins  = $app->getBalance();
            $this->template->origin = $app->getOrigin();
            $this->template->url    = $app->getURL() === "https://example.invalid/" ? "" : $app->getURL();
            $this->template->note   = $app->getNoteLink();
            $this->template->users  = $app->getUsersCount();
            $this->template->on     = $app->isEnabled();
            $this->template->owner  = $app->getOwner();
            $this->template->type   = $app->getType();
            $this->template->runner = $app->getRunner();
            $this->template->hasSwf = (bool) $app->getSwfHash();
            $this->template->secret = $app->getSecret();
        } else {
            $this->template->create = true;
            $this->template->type   = Application::TYPE_GAME;
            $this->template->runner = Application::RUNNER_HTML;
            $this->template->hasSwf = false;
            $this->template->secret = "";
        }
    }

    public function renderList(): void
    {
        $this->assertUserLoggedIn();

        $act = $this->queryParam("act");
        if (!in_array($act, ["catalog", "list", "installed", "soft", "dev"])) {
            $act = "catalog";
        }

        if ($act === "catalog") {
            $this->renderCatalog();
            return;
        }

        if ($act === "soft") {
            $this->renderSoft();
            return;
        }

        $page = (int) ($this->queryParam("p") ?? 1);
        if ($act == "list") {
            $apps  = $this->apps->getList($page);
            $count = $this->apps->getListCount();
        } elseif ($act == "installed") {
            $apps  = $this->apps->getInstalled($this->user->identity, $page);
            $count = $this->apps->getInstalledCount($this->user->identity);
        } elseif ($act == "dev") {
            $apps  = $this->apps->getByOwner($this->user->identity, $page);
            $count = $this->apps->getOwnCount($this->user->identity);
        }

        $this->template->act      = $act;
        $this->template->iterator = $apps;
        $this->template->count    = $count;
        $this->template->page     = $page;
    }

    private function renderSoft(): void
    {
        $this->template->_template = "Apps/Soft.latte";
        $this->template->act       = "soft";
        $this->template->clients   = $this->getOpenVkClients();
    }

    private function renderCatalog(): void
    {
        $newType = $this->queryParam("newtype") === "app" ? Application::TYPE_APP : Application::TYPE_GAME;

        $installed = iterator_to_array($this->apps->getInstalled($this->user->identity, 1, 12));
        $installed = array_filter($installed, fn ($app) => $app !== null);

        $promo = OPENVK_ROOT_CONF["openvk"]["preferences"]["apps"]["promo"] ?? [];
        if (!is_array($promo)) {
            $promo = [];
        }

        $this->template->_template       = "Apps/Catalog.latte";
        $this->template->act             = "catalog";
        $this->template->installed       = $installed;
        $this->template->installedCount  = $this->apps->getInstalledCount($this->user->identity);
        $this->template->friendsActivity = $this->apps->getFriendsActivity($this->user->identity, 10);
        $this->template->popularGames    = $this->apps->getPopular(Application::TYPE_GAME, 5);
        $this->template->newItems        = $this->apps->getNew($newType, 5);
        $this->template->newType         = $newType;
        $this->template->promo           = $promo;
    }

    /**
     * @return list<array{tag: string, name: string, url: string, img: string}>
     */
    private function getOpenVkClients(): array
    {
        $path = OPENVK_ROOT . "/data/clients.xml";
        if (!is_file($path)) {
            return [];
        }

        $xml = @simplexml_load_file($path);
        if ($xml === false) {
            return [];
        }

        $clients = [];
        $seen    = [];
        foreach ($xml as $client) {
            $name = (string) $client["name"];
            $url  = (string) $client["url"];
            $key  = mb_strtolower($name . "|" . $url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $clients[] = [
                "tag"  => (string) $client["tag"],
                "name" => $name,
                "url"  => $url,
                "img"  => (string) $client["img"],
            ];
        }

        return $clients;
    }
}
