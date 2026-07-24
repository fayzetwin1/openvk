<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use Chandler\Signaling\SignalManager;
use openvk\Web\Events\NewMessageEvent;
use openvk\Web\Models\Repositories\{Users, Clubs, Messages};
use openvk\Web\Models\Entities\{Message, Correspondence};
use openvk\Web\Util\LongpollGuard;

final class MessengerPresenter extends OpenVKPresenter
{
    private $messages;
    private $signaler;
    protected $presenterName = "messenger";

    public function __construct(Messages $messages)
    {
        $this->messages = $messages;
        $this->signaler = SignalManager::i();

        parent::__construct();
    }

    private function getCorrespondent(int $id): object
    {
        if ($id > 0) {
            return (new Users())->get($id);
        } elseif ($id < 0) {
            return (new Clubs())->get(abs($id));
        } elseif ($id === 0) {
            return $this->user->identity;
        }
    }

    public function renderIndex(): void
    {
        $this->assertUserLoggedIn();

        if (isset($_GET["sel"])) {
            $this->pass("openvk!Messenger->app", $_GET["sel"]);
        }

        $page = (int) ($_GET["p"] ?? 1);
        $correspondences = iterator_to_array($this->messages->getCorrespondencies($this->user->identity, $page));

        // #КакаоПрокакалось

        $this->template->corresps = $correspondences;
        $this->template->paginatorConf = (object) [
            "count"   => $this->messages->getCorrespondenciesCount($this->user->identity),
            "page"    => (int) ($_GET["p"] ?? 1),
            "amount"  => sizeof($this->template->corresps),
            "perPage" => OPENVK_DEFAULT_PER_PAGE,
            "tidy"    => false,
            "atTop"   => false,
        ];
    }

    public function renderApp(int $sel): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        if (!$this->user->identity->getPrivacyPermission('messages.write', $correspondent)) {
            $this->flash("err", tr("warning"), tr("user_may_not_reply"));
        }

        $this->template->disable_ajax  = 1;
        $this->template->selId         = $sel;
        $this->template->correspondent = $correspondent;
    }

    public function renderEvents(int $randNum): void
    {
        $this->assertUserLoggedIn();

        header("Content-Type: application/json");
        $this->assertLongpollBudget();

        if (!LongpollGuard::acquire($this->user->id)) {
            header("HTTP/1.1 429 Too Many Requests");
            header("Retry-After: 5");
            exit(json_encode(["error" => "longpoll_busy"]));
        }

        $this->signaler->listen(function ($event, $id) {
            exit(json_encode([[
                "UUID"  => $id,
                "event" => $event->getLongPoolSummary(),
            ]]));
        }, $this->user->id, 15);
    }

    public function renderVKEvents(int $id): void
    {
        header("Content-Type: application/json");

        if ($this->queryParam("act") !== "a_check") {
            header("HTTP/1.1 400 Bad Request");
            exit();
        } elseif (!$this->queryParam("key")) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $key   = $this->queryParam("key");
        $parts = explode(".", $key, 4);
        if (sizeof($parts) !== 4) {
            exit(json_encode([
                "failed" => 3,
            ]));
        }

        [$uid, $exp, $nonce, $sig] = $parts;
        $hmac = hash_hmac("sha256", "$uid|$exp|$nonce", CHANDLER_ROOT_CONF["security"]["secret"]);
        if ((int) $uid !== $id || (int) $exp < time() || !hash_equals($hmac, $sig)) {
            exit(json_encode([
                "failed" => 3,
            ]));
        }

        $this->assertLongpollBudget(json: true);

        if (!LongpollGuard::acquire($id)) {
            header("HTTP/1.1 429 Too Many Requests");
            header("Retry-After: 5");
            exit(json_encode([
                "failed"  => 2,
                "ts"      => time(),
                "updates" => [],
            ]));
        }

        $legacy = $this->queryParam("version") < 3;

        $time = intval($this->queryParam("wait"));

        if ($time > 15) {
            $time = 15;
        } elseif ($time == 0) {
            $time = 15;
        } // default

        $this->signaler->listen(function ($event, $eId) use ($id) {
            exit(json_encode([
                "ts"      => time(),
                "updates" => [
                    $event->getVKAPISummary($id),
                ],
            ]));
        }, $id, $time);
    }

    private function assertLongpollBudget(bool $json = false): void
    {
        $ip  = (new \openvk\Web\Models\Repositories\IPs())->get(CONNECTING_IP);
        $res = $ip->rateLimit(2);
        if ($res === \openvk\Web\Models\Entities\IP::RL_RESET || $res === \openvk\Web\Models\Entities\IP::RL_CANEXEC) {
            return;
        }

        header("HTTP/1.1 429 Too Many Requests");
        header("Retry-After: 20");
        if ($json) {
            exit(json_encode([
                "failed"  => 2,
                "ts"      => time(),
                "updates" => [],
            ]));
        }

        exit(json_encode(["error" => "rate_limited"]));
    }

    public function renderApiGetMessages(int $sel, int $lastMsg): void
    {
        $this->assertUserLoggedIn();

        $correspondent = $this->getCorrespondent($sel);
        if (!$correspondent) {
            $this->notFound();
        }

        $messages       = [];
        $correspondence = new Correspondence($this->user->identity, $correspondent);
        foreach ($correspondence->getMessages(1, $lastMsg === 0 ? null : $lastMsg, null, 0) as $message) {
            $messages[] = $message->simplify();
        }

        header("Content-Type: application/json");
        exit(json_encode($messages));
    }

    public function renderApiWriteMessage(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        if (empty($this->postParam("content"))) {
            header("HTTP/1.1 400 Bad Request");
            exit("<b>Argument error</b>: param 'content' expected to be string, undefined given.");
        }

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $msg = new Message();
        $msg->setContent($this->postParam("content"));
        $cor->sendMessage($msg);

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($msg->simplify()));
    }

    public function renderApiSendTypingStatus(int $sel): void
    {
        $this->assertUserLoggedIn();
        $this->willExecuteWriteAction();

        $sel = $this->getCorrespondent($sel);
        if ($sel->getId() !== $this->user->id && !$sel->getPrivacyPermission('messages.write', $this->user->identity)) {
            header("HTTP/1.1 403 Forbidden");
            exit();
        }

        $cor = new Correspondence($this->user->identity, $sel);
        $result = $cor->sendTypingEvent();

        header("HTTP/1.1 202 Accepted");
        header("Content-Type: application/json");
        exit(json_encode($result));
    }
}
