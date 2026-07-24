<?php

declare(strict_types=1);

namespace openvk\Web\Presenters;

use openvk\Web\Models\Repositories\BannedLinks;

final class AwayPresenter extends OpenVKPresenter
{
    public function renderAway(): void
    {
        $redirTo = ovk_safe_external_url($this->queryParam("to"));
        if ($redirTo === null) {
            $this->notFound();
        }

        if (OPENVK_ROOT_CONF["openvk"]["preferences"]["susLinks"]["warnings"] ?? false) {
            $checkBanEntries = (new BannedLinks())->check($redirTo);
            if (sizeof($checkBanEntries) > 0) {
                $this->pass("openvk!Away->view", $checkBanEntries[0]);
            }
        }

        $parts = parse_url($redirTo);
        $domainTo = strtolower(str_replace("www.", "", (string) ($parts["host"] ?? "")));
        $mirrors  = OPENVK_ROOT_CONF["openvk"]["mirrors"] ?? [];
        $isMirror = is_array($mirrors) && in_array($domainTo, array_map(
            static fn ($m) => strtolower(str_replace("www.", "", (string) $m)),
            $mirrors
        ), true);

        if ($isMirror) {
            $currentDomain = ovk_public_host();
            $redirTo = preg_replace(
                '#^(https?://)' . preg_quote((string) ($parts["host"] ?? ""), "#") . '#i',
                '$1' . $currentDomain,
                $redirTo,
                1
            ) ?? $redirTo;

            header("HTTP/1.0 302 Found");
            header("X-Robots-Tag: noindex, nofollow, noarchive");
            header("Location: " . $redirTo);
            exit;
        }

        // External destinations never auto-redirect — interstitial only (anti open-redirect / phishing).
        header("X-Robots-Tag: noindex, nofollow, noarchive");
        $this->template->link = null;
        $this->template->to   = $redirTo;
        $this->template->_template = "Away/View.latte";
    }

    public function renderView(int $lid): void
    {
        $this->template->link = (new BannedLinks())->get($lid);

        if (!$this->template->link) {
            $this->notFound();
        }

        $to = ovk_safe_external_url($this->queryParam("to"));
        $this->template->to = $to ?? "/";
    }
}
