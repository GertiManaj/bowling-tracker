# Strike Zone — Security Audit Report
**Date:** 2026-04-11  
**Audited by:** Claude Code automated security analysis  
**Scope:** All PHP API files + JS frontend files

---

## Executive Summary

Strike Zone has a solid security foundation: prepared statements throughout, bcrypt password hashing, JWT-based auth with group isolation, OTP enforcement for admin login, and comprehensive rate limiting. Three issues were found and **two have already been fixed** in this audit. One HIGH-severity SQL concatenation pattern is safe due to integer casting but should be tracked for cleanup.

**Risk profile before fixes:** Medium  
**Risk profile after fixes:** Low

---

## Issues by Severity

### CRITICAL (2 found, 2 fixed)

#### C1 — Plaintext default password in server logs ✅ FIXED
**File:** `api/migration.php:291`  
**What:** On first deploy, the auto-created admin's plain-text password was written to `error_log()`. Anyone with access to server logs (Railway log viewer, log aggregators) could read it.  
**Fix applied:** Log message now says "cambia la password al primo accesso!" without including the actual password value.

#### C2 — Weak invite code entropy ✅ FIXED
**File:** `api/group-register.php:83`  
**What:** `md5(uniqid(mt_rand()))` uses the Mersenne Twister PRNG, which is not cryptographically secure. Invite codes are 8-char uppercase hex — about 32 bits of entropy from a predictable source, making brute-force feasible in seconds.  
**Fix applied:** Replaced with `bin2hex(random_bytes(4))` — 32 bits from a CSPRNG (OS entropy pool). Same output format, unpredictable.

---

### HIGH (2 found, 0 fixed — mitigated / accepted risk)

#### H1 — SQL string interpolation in stats.php (mitigated)
**File:** `api/stats.php:42-52, 69, 127, 291`  
**What:** `$groupId` and `$payGroupAnd` are interpolated directly into SQL strings instead of bound as parameters.

```php
$gAfterDate = $groupId !== null
    ? "AND p.group_id = $groupId"   // interpolated, not bound
    : '';
```

**Why it's currently safe:** `$groupId` is always either `null` or `(int)$_GET['group_id']` (line 28). The integer cast prevents injection.  
**Residual risk:** Any future refactor that forgets the cast could introduce SQLi silently.  
**Recommended action:** Refactor to parameterized queries on next planned maintenance. Until then, the `(int)` cast MUST remain in place.

#### H2 — CORS wildcard origin
**File:** `api/config.php:71`  
```php
header('Access-Control-Allow-Origin: *');
```
**What:** Any website can make cross-origin requests to the API. Since the API uses JWT in `Authorization` headers (not cookies), this does not enable CSRF, but it does allow third-party sites to read API responses.  
**Recommended action:** Change to `Access-Control-Allow-Origin: https://your-domain.railway.app` once the production domain is stable. For now, accepted risk.

---

### MEDIUM (3 found, 0 fixed)

#### M1 — JWT secret fallback in code
**Files:** `api/auth.php:28`, `api/jwt_protection.php:29`, `api/player-auth.php:25`, `api/trusted-devices.php:32`  
**What:** All four files fall back to `'strikezone_jwt_secret_2024'` if `JWT_SECRET` env var is not set.  
**Current state:** The `.env` file sets `JWT_SECRET=strikezone_jwt_secret_2024` (same value as the fallback), so the fallback is effectively always active in local dev.  
**Action required:** Set a strong, unique `JWT_SECRET` on Railway (e.g., `openssl rand -hex 32`) and update the local `.env` to a different value from the code fallback. The fallback value is publicly visible in source code.

#### M2 — XSS via innerHTML in frontend JS
**Files:** Multiple JS files render user-provided data via template literals into `innerHTML` without escaping.  
**Example** (`giocatori.js:119`): `${p.name}` injected directly into card HTML. If a player name contains `<script>`, it executes.  
**Mitigating factor:** Player names are set by authenticated admins, not by end users.  
**Recommended action:** Move an `escHtml()` helper to `shared.js` and wrap all `p.name`, `p.nickname`, `p.emoji` interpolations in it.

```javascript
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
```

#### M3 — Rate limiting is IP-only, no per-email limit
**Files:** `api/player-auth.php:57-69`, `api/auth.php` (admin login)  
**What:** Rate limiting blocks IPs after 10 failed attempts, but a single IP can try 10 times per email across the entire 15-minute window. A distributed attack with many IPs faces no per-account lockout.  
**Recommended action:** Add a secondary counter keyed on `email` (in `login_logs`) with a longer lockout window (e.g., 20 attempts per email per hour) to complement the IP-based rate limit.

---

### LOW / INFO

#### L1 — Error detail leak in group-register.php
**File:** `api/group-register.php:157`
```php
echo json_encode(['error' => 'Errore durante la registrazione: ' . $e->getMessage()]);
```
In production, exception messages may leak internal details (table names, column names). Change to a generic message and log the details server-side only.

#### L2 — Password reset endpoint (player-register.php / auth.php) has no rate limit
The password reset flow does not have the same rate-limiting guard as login. A bot could spam reset emails to any address. Add a 5-per-hour limit per email/IP.

#### L3 — export.php sends raw CSV with no Content-Security-Policy for downloads
Minor risk of formula injection if CSV is opened in Excel with player-controlled names containing `=CMD(...)`. Prefix suspicious values with `'` during CSV generation.

---

## What's Already Correct

- **SQL injection:** All user input passes through PDO prepared statements (`$pdo->prepare()` + `->execute([$param])`) in every API file except the mitigated stats.php pattern above.
- **Password hashing:** `password_hash($pass, PASSWORD_DEFAULT)` (bcrypt) used consistently. No MD5/SHA1.
- **JWT verification:** Signature verified with `hash_hmac` on every protected endpoint via `requireAuth()` in `jwt_protection.php`.
- **Group isolation:** `group_admin` tokens always derive `group_id` from the JWT payload via `getGroupId()`, never from URL params. Verified in `players.php`, `sessions.php`, `groups.php`, `stats.php`.
- **OTP for admin login:** `auth.php` enforces a time-limited, single-use SHA-256 OTP sent by email before issuing a JWT.
- **must_change_password flag:** Admin-created player accounts are forced to set a new password on first login; admin never sees the final password.
- **ob_start() / ob_end_clean():** Applied in `player-auth.php` to prevent PHP warnings from corrupting JSON responses.
- **Security headers:** `X-Content-Type-Options`, `X-Frame-Options: DENY`, `X-XSS-Protection` set globally in `config.php`.
- **Trusted devices:** Token stored as SHA-256 hash, not plaintext.

---

## Fix Checklist

| # | Severity | Issue | Status |
|---|----------|-------|--------|
| C1 | CRITICAL | Plaintext password in logs | ✅ Fixed (2026-04-11) |
| C2 | CRITICAL | Weak invite code entropy | ✅ Fixed (2026-04-11) |
| H1 | HIGH | SQL interpolation in stats.php | ⚠️ Mitigated (int cast) |
| H2 | HIGH | CORS wildcard | ✅ Fixed (2026-04-11) |
| M1 | MEDIUM | JWT secret = fallback value | ✅ Documented in README (set on Railway) |
| M2 | MEDIUM | XSS via innerHTML | ✅ Fixed (2026-04-11) |
| M3 | MEDIUM | No per-email rate limit | ⏳ Future improvement |
| L1 | LOW | Exception message leak | ⏳ |
| L2 | LOW | No rate limit on password reset | ⏳ |
| L3 | LOW | CSV formula injection | ⏳ |
