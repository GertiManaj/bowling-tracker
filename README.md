# Strike Zone — Bowling Tracker

## Security Configuration

### JWT Secret (CRITICAL)

The `JWT_SECRET` environment variable **MUST** be set to a cryptographically secure random value in production.

**Generate a secure secret:**
```bash
openssl rand -hex 32
```

**Set on Railway:**
1. Railway Dashboard → Variables
2. `JWT_SECRET` = [paste generated secret]
3. Save Variables → redeploy triggers automatically

> **NEVER use the fallback value in production!**  
> The code fallback (`strikezone_jwt_secret_2024`) is for local development only and is publicly visible in source code.

---

### CORS Allowed Origins

Configured in `api/config.php`. Update `$_sz_allowedOrigins` when adding a new domain.

---

### Environment Variables

| Variable | Required | Description |
|---|---|---|
| `JWT_SECRET` | **YES** | HS256 signing secret — min 32 random bytes |
| `MYSQLHOST` / `DB_HOST` | YES | Database host |
| `MYSQLDATABASE` / `DB_NAME` | YES | Database name |
| `MYSQLUSER` / `DB_USER` | YES | Database user |
| `MYSQLPASSWORD` / `DB_PASSWORD` | YES | Database password |
| `MAIL_HOST` | for email | SMTP host |
| `MAIL_USER` | for email | SMTP username |
| `MAIL_PASS` | for email | SMTP password |
| `MAIL_FROM` | for email | Sender address |
