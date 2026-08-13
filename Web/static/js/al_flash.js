/**
 * Ruffle + VK Flash API bridge (APIConnection / ExternalInterface).
 * Reuses permission / payment UX patterns from al_games.js.
 */
(function () {
    "use strict";

    const perms = {
        friends: [tr("appjs_act_friends"), tr("appjs_act_friends_desc")],
        wall: [tr("appjs_act_wall"), tr("appjs_act_wall_desc")],
        messages: [tr("appjs_act_messages"), tr("appjs_act_messages_desc")],
        groups: [tr("appjs_act_groups"), tr("appjs_act_groups_desc")],
        likes: [tr("appjs_act_likes"), tr("appjs_act_likes_desc")]
    };

    function toQueryString(obj, prefix) {
        var str = [],
            p;
        for (p in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, p)) {
                var k = prefix ? prefix + "[" + p + "]" : p,
                    v = obj[p];
                str.push(
                    v !== null && typeof v === "object"
                        ? toQueryString(v, k)
                        : encodeURIComponent(k) + "=" + encodeURIComponent(v)
                );
            }
        }
        return str.join("&");
    }

    async function ensureDomainPermission(domain) {
        if (window.appPerms.includes(domain)) {
            return true;
        }

        if (typeof perms[domain] === "undefined") {
            return false;
        }

        var dInfo = perms[domain];
        var allowed = false;
        await new Promise(function (r) {
            MessageBox(
                tr("appjs_act_request"),
                "<p>" +
                    tr("app") +
                    " <b>" +
                    window.appTitle +
                    "</b> " +
                    tr("appjs_act_requests") +
                    " <b>" +
                    dInfo[0] +
                    "</b>. " +
                    tr("appjs_act_can") +
                    " <b>" +
                    dInfo[1] +
                    "</b>.",
                [tr("appjs_act_allow"), tr("appjs_act_disallow")],
                [
                    function () {
                        API.Apps.updatePermission(window.appId, domain, "yes").then(function () {
                            window.appPerms.push(domain);
                            allowed = true;
                            r();
                        });
                    },
                    function () {
                        r();
                    }
                ]
            );
        });

        return allowed;
    }

    async function callVkMethod(method, params) {
        if (!/^[a-z]+\.[a-z0-9]+$/i.test(method) && !/^[a-z0-9_]+$/i.test(method)) {
            throw new Error("API Method name is invalid");
        }

        // Legacy short names -> modern-ish OpenVK methods
        var aliases = {
            getProfiles: "users.get",
            getUserInfo: "users.get",
            getFriends: "friends.get"
        };
        if (aliases[method]) {
            method = aliases[method];
        }

        if (method.indexOf(".") === -1) {
            throw new Error("API Method name is invalid");
        }

        var domain = method.split(".")[0].toLowerCase();
        if (domain === "newsfeed") {
            domain = "wall";
        }
        if (domain === "users" || domain === "getprofiles") {
            // users.get is generally allowed for the viewer
        } else if (!(await ensureDomainPermission(domain))) {
            if (typeof perms[domain] === "undefined") {
                throw new Error("This API method is not supported");
            }
            throw new Error("No permission to use this method");
        }

        params = params || {};
        var qs = toQueryString(params);
        var apiResponse = await (
            await fetch("/method/" + method + "?auth_mechanism=roaming&" + qs)
        ).json();

        if (typeof apiResponse.error_code !== "undefined") {
            throw new Error(apiResponse.error_code + ": " + apiResponse.error_msg);
        }

        return apiResponse.response;
    }

    function notifyFlash(player, callbackName, args) {
        if (!player || !callbackName) {
            return;
        }
        try {
            if (typeof player.call === "function") {
                player.call(callbackName, args);
            } else if (typeof player[callbackName] === "function") {
                player[callbackName].apply(player, Array.isArray(args) ? args : [args]);
            }
        } catch (e) {
            console.warn("[al_flash] notifyFlash failed", callbackName, e);
        }
    }

    function handlePaymentBox(params, onDone) {
        var amount = parseFloat((params && (params.votes || params.amount || params.sum)) || 0);
        var item = (params && (params.description || params.item || "item")) || "item";
        if (!(amount > 0)) {
            onDone(false, "invalid sum");
            return;
        }

        MessageBox(
            tr("appjs_payment"),
            "<p>" +
                tr("appjs_payment_intro") +
                " <b>" +
                window.appTitle +
                "</b>.<br/>" +
                tr("appjs_order_items") +
                ": <b>" +
                item +
                "</b></p>" +
                "<p>" +
                tr("appjs_payment_total") +
                ": <big><b>" +
                amount +
                "</b></big> " +
                tr("points_count") +
                ".</p>",
            [tr("appjs_payment_confirm"), tr("cancel")],
            [
                async function () {
                    try {
                        var sign = await API.Apps.pay(window.appId, amount);
                        onDone(true, { votes: amount, signature: sign });
                    } catch (e) {
                        MessageBox(tr("error"), tr("appjs_err_funds"), ["OK"], [Function.noop]);
                        onDone(false, e.message || "payment error");
                    }
                },
                function () {
                    onDone(false, "User cancelled payment");
                }
            ]
        );
    }

    function installExternalInterface(player) {
        // VK APIConnection typically calls parent JS `api(callId, method, params)`
        // and expects `apiCallback(callId, data)` / error callback on the SWF.
        window.api = async function (callId, method, params) {
            try {
                var response = await callVkMethod(method, params || {});
                notifyFlash(player, "apiCallback", [callId, response]);
                notifyFlash(player, "APICallback", [callId, response]);
            } catch (e) {
                var err = { error_code: 1, error_msg: String(e.message || e) };
                notifyFlash(player, "apiCallback", [callId, err]);
                notifyFlash(player, "APICallback", [callId, err]);
            }
        };

        window.apiCall = window.api;

        window.callMethod = function (method) {
            var args = Array.prototype.slice.call(arguments, 1);
            var params = args[0] || {};
            switch (String(method)) {
                case "showPaymentBox":
                case "showOrderBox":
                    handlePaymentBox(params, function (ok, data) {
                        if (ok) {
                            notifyFlash(player, "onOrderSuccess", data);
                            notifyFlash(player, "onBalanceChanged", data);
                        } else {
                            notifyFlash(player, "onOrderFail", data);
                            notifyFlash(player, "onOrderCancel", data);
                        }
                    });
                    break;
                case "showInviteBox":
                    MessageBox(
                        tr("apps"),
                        tr("app_flash_invite_stub"),
                        ["OK"],
                        [Function.noop]
                    );
                    break;
                case "showSettingsBox":
                    MessageBox(
                        tr("apps"),
                        tr("app_flash_settings_stub"),
                        ["OK"],
                        [Function.noop]
                    );
                    break;
                case "resizeWindow":
                    try {
                        var w = parseInt(params.width || params[0] || 607, 10);
                        var h = parseInt(params.height || params[1] || 600, 10);
                        var stage = document.getElementById("appFlash");
                        if (stage && w > 0 && h > 0) {
                            stage.style.width = w + "px";
                            stage.style.height = h + "px";
                        }
                    } catch (e) {}
                    break;
                default:
                    console.info("[al_flash] callMethod stub:", method, params);
            }
        };

        // Some older wrappers look these up on window
        window.VK = window.VK || {};
        window.VK.callMethod = window.callMethod;
        window.VK.api = function (method, params, onSuccess, onError) {
            callVkMethod(method, params || {})
                .then(function (response) {
                    if (typeof onSuccess === "function") {
                        onSuccess(response);
                    }
                })
                .catch(function (e) {
                    if (typeof onError === "function") {
                        onError({ error_code: 1, error_msg: String(e.message || e) });
                    }
                });
        };
    }

    async function boot() {
        var stage = document.getElementById("appFlash");
        // #region agent log
        fetch('http://localhost:7529/ingest/aed4c84e-c5ad-490d-9ad2-0fd3e310b9d6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'d99942'},body:JSON.stringify({sessionId:'d99942',hypothesisId:'H3',location:'al_flash.js:boot',message:'boot entry',data:{hasStage:!!stage,swfUrl:String(window.appSwfUrl||''),hasRuffle:!!window.RufflePlayer,flashVarsKeys:Object.keys(window.appFlashVars||{}),appId:window.appId||null},timestamp:Date.now()})}).catch(()=>{});
        // #endregion
        if (!stage || !window.appSwfUrl) {
            return;
        }

        if (!window.RufflePlayer) {
            console.error("[al_flash] RufflePlayer is not available");
            // #region agent log
            fetch('http://localhost:7529/ingest/aed4c84e-c5ad-490d-9ad2-0fd3e310b9d6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'d99942'},body:JSON.stringify({sessionId:'d99942',hypothesisId:'H3',location:'al_flash.js:boot',message:'RufflePlayer missing',data:{},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
            stage.innerHTML =
                "<div style='color:#fff;padding:20px;text-align:left'>" +
                tr("app_err_ruffle") +
                "</div>";
            return;
        }

        window.RufflePlayer.config = window.RufflePlayer.config || {};
        window.RufflePlayer.config.autoplay = "on";
        window.RufflePlayer.config.unmuteOverlay = "hidden";
        window.RufflePlayer.config.letterbox = "on";

        var ruffle = window.RufflePlayer.newest();
        var player = ruffle.createPlayer();
        player.style.width = "100%";
        player.style.height = "100%";
        stage.appendChild(player);

        installExternalInterface(player);

        var parameters = {};
        var fv = window.appFlashVars || {};
        Object.keys(fv).forEach(function (k) {
            parameters[k] = String(fv[k]);
        });

        try {
            await player.load({
                url: window.appSwfUrl,
                parameters: parameters,
                allowScriptAccess: true,
                autoplay: "on"
            });
            // #region agent log
            fetch('http://localhost:7529/ingest/aed4c84e-c5ad-490d-9ad2-0fd3e310b9d6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'d99942'},body:JSON.stringify({sessionId:'d99942',hypothesisId:'H3',location:'al_flash.js:boot',message:'player.load ok',data:{paramCount:Object.keys(parameters).length},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
        } catch (e) {
            console.error("[al_flash] failed to load SWF", e);
            // #region agent log
            fetch('http://localhost:7529/ingest/aed4c84e-c5ad-490d-9ad2-0fd3e310b9d6',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'d99942'},body:JSON.stringify({sessionId:'d99942',hypothesisId:'H3',location:'al_flash.js:boot',message:'player.load failed',data:{error:String(e&&e.message||e)},timestamp:Date.now()})}).catch(()=>{});
            // #endregion
            stage.innerHTML =
                "<div style='color:#fff;padding:20px;text-align:left'>" +
                tr("app_err_swf_play") +
                "</div>";
        }
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot);
    } else {
        boot();
    }
})();
