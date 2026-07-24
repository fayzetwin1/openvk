#!/usr/bin/env bash
# Shared helpers for OpenVK security STR runner.

if [[ -t 1 ]]; then
  C_RED=$'\033[31m'
  C_GRN=$'\033[32m'
  C_YEL=$'\033[33m'
  C_CYN=$'\033[36m'
  C_DIM=$'\033[2m'
  C_RST=$'\033[0m'
else
  C_RED=""; C_GRN=""; C_YEL=""; C_CYN=""; C_DIM=""; C_RST=""
fi

COUNT_VULN=0
COUNT_OK=0
COUNT_SKIP=0
COUNT_MANUAL=0
REPORT_LINES=()

log() {
  printf '%s\n' "$*"
  REPORT_LINES+=("$*")
}

section() {
  log ""
  log "${C_CYN}═══ $* ═══${C_RST}"
}

vuln() {
  COUNT_VULN=$((COUNT_VULN + 1))
  log "${C_RED}[VULN]${C_RST} $*"
}

ok() {
  COUNT_OK=$((COUNT_OK + 1))
  log "${C_GRN}[OK]${C_RST}   $*"
}

skip() {
  COUNT_SKIP=$((COUNT_SKIP + 1))
  log "${C_YEL}[SKIP]${C_RST} $*"
}

manual() {
  COUNT_MANUAL=$((COUNT_MANUAL + 1))
  log "${C_DIM}[MANUAL]${C_RST} $*"
}

info() {
  log "${C_DIM}       $*${C_RST}"
}

require_base_url() {
  if [[ -z "${BASE_URL}" || "${BASE_URL}" == *"CHANGE_ME"* ]]; then
    log "${C_RED}Set BASE_URL in str_all.sh (or export BASE_URL=https://your-instance)${C_RST}"
    exit 1
  fi
  BASE_URL="${BASE_URL%/}"
}

# curl_capture URL [extra curl args...]
# Sets: HTTP_CODE, RESP_HEADERS, RESP_BODY, RESP_CT
curl_capture() {
  local url=$1
  shift
  local tmp_h tmp_b
  tmp_h=$(mktemp)
  tmp_b=$(mktemp)
  HTTP_CODE=$(curl -sS -L --max-redirs 0 --connect-timeout 5 --max-time "${CURL_TIMEOUT:-15}" \
    -D "$tmp_h" -o "$tmp_b" -w '%{http_code}' "$@" "$url" 2>/dev/null) || HTTP_CODE="000"
  [[ -z "${HTTP_CODE}" ]] && HTTP_CODE="000"
  RESP_HEADERS=$(cat "$tmp_h" 2>/dev/null || true)
  RESP_BODY=$(cat "$tmp_b" 2>/dev/null || true)
  RESP_CT=$(printf '%s' "$RESP_HEADERS" | tr -d '\r' | { grep -i '^Content-Type:' || true; } | tail -1 | sed 's/^[Cc]ontent-[Tt]ype:[[:space:]]*//')
  rm -f "$tmp_h" "$tmp_b"
}

# Follow redirects disabled; capture first Location
curl_headers_only() {
  local url=$1
  shift
  RESP_HEADERS=$(curl -sS -I --max-redirs 0 --connect-timeout 5 --max-time "${CURL_TIMEOUT:-15}" "$@" "$url" 2>/dev/null || true)
  HTTP_CODE=$(printf '%s' "$RESP_HEADERS" | tr -d '\r' | head -1 | awk '{print $2}')
  RESP_LOCATION=$(printf '%s' "$RESP_HEADERS" | tr -d '\r' | { grep -i '^Location:' || true; } | tail -1 | sed 's/^[Ll]ocation:[[:space:]]*//')
  RESP_CT=$(printf '%s' "$RESP_HEADERS" | tr -d '\r' | { grep -i '^Content-Type:' || true; } | tail -1 | sed 's/^[Cc]ontent-[Tt]ype:[[:space:]]*//')
}

header_value() {
  local name=$1
  printf '%s' "$RESP_HEADERS" | tr -d '\r' | { grep -i "^${name}:" || true; } | tail -1 | sed "s/^[^:]*:[[:space:]]*//"
}

cookie_args() {
  if [[ -n "${COOKIE_JAR:-}" && -f "${COOKIE_JAR}" ]]; then
    echo -b "${COOKIE_JAR}"
  elif [[ -n "${COOKIE_HEADER:-}" ]]; then
    echo -H "Cookie: ${COOKIE_HEADER}"
  fi
}

admin_cookie_args() {
  if [[ -n "${COOKIE_ADMIN:-}" && -f "${COOKIE_ADMIN}" ]]; then
    echo -b "${COOKIE_ADMIN}"
  elif [[ -n "${COOKIE_ADMIN_HEADER:-}" ]]; then
    echo -H "Cookie: ${COOKIE_ADMIN_HEADER}"
  else
    cookie_args
  fi
}

repo_file() {
  local rel=$1
  if [[ -n "${REPO_ROOT:-}" && -f "${REPO_ROOT}/${rel}" ]]; then
    echo "${REPO_ROOT}/${rel}"
  else
    echo ""
  fi
}

summary() {
  section "Summary"
  log "VULN=${COUNT_VULN}  OK=${COUNT_OK}  SKIP=${COUNT_SKIP}  MANUAL=${COUNT_MANUAL}"
  if [[ "${COUNT_VULN}" -gt 0 ]]; then
    log "${C_RED}Instance still shows exploitable issues — share this report with the team.${C_RST}"
  else
    log "${C_GRN}No automatic VULN hits (checks may be SKIP/MANUAL).${C_RST}"
  fi
}

write_report() {
  local out=${1:-}
  [[ -z "$out" ]] && return 0
  {
    echo "OpenVK security STR report"
    echo "BASE_URL=${BASE_URL}"
    echo "generated=$(date -Is 2>/dev/null || date)"
    echo ""
    printf '%s\n' "${REPORT_LINES[@]}" | sed 's/\x1b\[[0-9;]*m//g'
  } >"$out"
  log ""
  log "Report written to ${out}"
}
