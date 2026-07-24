# OpenVK security STR (steps to reproduce)

Demonstrate P0/P1/Residual findings from the security audit on **your** instance. Not a weaponized exploit pack — safe defaults; DoS/SQL probes are opt-in.

## Quick start

```bash
cd /path/to/openvk

# 1. Edit BASE_URL in tests/security/str_all.sh  (or export it)
export BASE_URL="https://your-staging.example"

# 2. Run
chmod +x tests/security/str_all.sh
./tests/security/str_all.sh
```

Report: stdout + `tests/security/last_report.txt`  
Generated CSRF page: `tests/security/evil_csrf.generated.html`

## Optional env vars

| Variable | Purpose |
|----------|---------|
| `COOKIE_JAR` | Netscape cookie file (logged-in user) |
| `COOKIE_HEADER` | Raw `Cookie:` header instead of jar |
| `COOKIE_ADMIN` / `COOKIE_ADMIN_HEADER` | Admin / Ban-write session |
| `PHOTO_ID` | Private photo id → thumbnail IDOR |
| `POST_OWNER` + `POST_ID` | Closed post → iapi photo leak |
| `CLUB_ID` + `ATTACKER_USER_ID` | Club setAdmin CSRF URL |
| `ACCESS_TOKEN` + `TARGET_USER_ID` | Longpoll key / XOR forge notes |
| `STR_ALLOW_DOS=1` | Parallel `/im12` probe (`STR_DOS_N`, default 30) |
| `STR_ALLOW_SQL_PROBE=1` | Print SQL probe steps (staging only) |

Example with cookies and photo IDOR:

```bash
BASE_URL="https://staging" \
COOKIE_JAR="$HOME/ovk-cookies.txt" \
PHOTO_ID=12345 \
CLUB_ID=1 ATTACKER_USER_ID=2 \
./tests/security/str_all.sh
```

## Output legend

- **`[VULN]`** — check indicates the issue is present
- **`[OK]`** — check suggests fixed / mitigated
- **`[SKIP]`** — missing config or inconclusive
- **`[MANUAL]`** — needs browser / second account / careful staging use

## 5-minute team demo

1. Run `str_all.sh` — screenshot the `[VULN]` block  
2. Browser: `/method/utils.getServerTime?callback=alert(1)//`  
3. Browser: `/away.php?to=https://example.com/`  
4. Browser: `/admin/sandbox`  
5. Optional: open `evil_csrf.generated.html` as club owner  
6. Do **not** enable `STR_ALLOW_DOS=1` on production without warning  

## Files

| File | Role |
|------|------|
| `str_all.sh` | Main runner (full map) |
| `lib.sh` | Colors, curl helpers, counters |
| `evil_csrf.html` | CSRF / GET-write template |
| `last_report.txt` | Last run (generated) |

## Coverage map

See plan / audit: JSONP, open redirects, OAuth `redirect_uri`, photo IDOR, iapi leak, Admin/NoSpam SQLi (manual/opt-in), `/rpc` CSRF, club CSRF, longpoll DoS, XOR key, source XSS, CORS, money CSRF, SMS OTP, login RL, sandbox, Blob no-op, Shell escape, KB, Apps.pay(0), maintenance bypass, Host header.
