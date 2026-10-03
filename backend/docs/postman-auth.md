# Calling the auth API from Postman

The API uses **Sanctum SPA cookie authentication**: a session cookie plus a CSRF token, no bearer tokens.
Postman has to imitate the React app at `http://localhost:5173`, or Sanctum treats requests as stateless and ignores the session cookie.

Base URL: `http://localhost:8000`. Always use `localhost`, never `127.0.0.1`: cookies are set for `localhost` (`SESSION_DOMAIN=localhost`) and are not sent to `127.0.0.1`.

## 1. One-time setup

1. Start the backend: `php artisan serve` (serves on `http://localhost:8000`).
2. Create an environment (e.g. "Diamond Bank local") with a variable `base_url = http://localhost:8000`.
3. Use the Postman desktop app (the web version can't store `localhost` cookies without the agent). Cookies are kept per domain; you can inspect them via **Cookies** under the Send button.
4. On the collection (Collection → **Headers**, or add to every request), set:

   | Header | Value | Why |
   |---|---|---|
   | `Accept` | `application/json` | JSON errors instead of HTML/redirects |
   | `Origin` | `http://localhost:5173` | Sanctum only starts a session for requests from a stateful domain (`SANCTUM_STATEFUL_DOMAINS`) |
   | `Referer` | `http://localhost:5173/` | Same check; Sanctum accepts either, sending both is safest |

5. On the collection, add this **Pre-request Script**. It copies the `XSRF-TOKEN` cookie into the `X-XSRF-TOKEN` header on every request. The cookie value is URL-encoded, so it must be decoded first.

   ```js
   const token = pm.cookies.get('XSRF-TOKEN');
   if (token) {
       pm.request.headers.upsert({ key: 'X-XSRF-TOKEN', value: decodeURIComponent(token) });
   }
   ```

   If `pm.cookies` is empty, allow-list the domain: **Cookies** (under Send) → **Domains Allowlist** → add `localhost`.

## 2. Order of requests

### Step 1: get the CSRF cookie (always first)

```
GET {{base_url}}/sanctum/csrf-cookie
```

Expect **204 No Content**. Under **Cookies** you should now see `XSRF-TOKEN` and `laravel-session` for `localhost`.
Repeat this step whenever you get **419 CSRF token mismatch**, e.g. after the session expires (120 minutes) or after logout.

### Step 2 (optional): list branches for registration

```
GET {{base_url}}/api/v1/branches
```

### Step 3: register

Customer (active immediately, KYC `PENDING`):

```
POST {{base_url}}/api/v1/auth/register/customer
{
  "first_name": "Awa", "last_name": "Jallow", "date_of_birth": "1995-04-12", "gender": "F",
  "national_id": "GM-123456", "phone": "7001234", "email": "awa@example.test", "branch_id": 1,
  "user_name": "awa", "password": "secret123", "password_confirmation": "secret123"
}
```

Staff (`PENDING` until an admin approves; there is no approval endpoint yet, so set `users.status = 'ACTIVE'` in Workbench to test):

```
POST {{base_url}}/api/v1/auth/register/staff
{
  "full_name": "Lamin Ceesay", "national_id": "GM-STAFF-1", "position": "Teller", "phone": "7005555",
  "email": "lamin@diamondbank.test", "user_name": "lamin",
  "password": "secret123", "password_confirmation": "secret123"
}
```

### Step 4: log in

```
POST {{base_url}}/api/v1/auth/login
{ "user_name": "awa", "password": "secret123" }
```

Login regenerates the session, so Postman receives a new `laravel-session` cookie and a new `XSRF-TOKEN`. The pre-request script picks up the new token automatically.

Possible responses:

| Status | Meaning |
|---|---|
| 200 | Logged in; `data` is the same as `/auth/me` |
| 401 | `Invalid username or password.` (same message whether or not the user exists) |
| 403 | `Your account is awaiting approval.` or `Your account is blocked. Please contact the bank.` |
| 429 | `Too many login attempts. Try again in N seconds.` See "Rate limits" below |
| 400 | No session: you skipped step 1 or the `Origin`/`Referer` header |

### Step 5: authenticated calls

```
GET  {{base_url}}/api/v1/auth/me
POST {{base_url}}/api/v1/auth/change-password
     { "current_password": "secret123", "password": "newSecret456", "password_confirmation": "newSecret456" }
```

If `must_change_password` is `true` (e.g. the seeded admin), every authenticated route except change-password and logout returns **403** with `data.must_change_password: true` until the password is changed.

### Step 6: log out

```
POST {{base_url}}/api/v1/auth/logout
```

The session is invalidated. Go back to step 1 before logging in again.

## Rate limits on login

Only failed attempts count; checks run before the password is verified.

- **5 failures per minute** for the same user name from the same IP.
- **20 failures per hour** for the same user name from any IP.

User names are compared case-insensitively. Either limit returns the same 429 with a `Retry-After` header. The account itself is never locked or blocked. A successful login clears the per-IP counter only.

## Troubleshooting

| Symptom | Cause |
|---|---|
| 419 CSRF token mismatch | No `X-XSRF-TOKEN` header, or a stale one: redo step 1 and check the pre-request script |
| 401 on `/auth/me` right after a successful login | `Origin`/`Referer` missing, or you mixed `localhost` and `127.0.0.1` |
| 400 "Login needs a session" | Same as above, on the login request itself |
| 422 | Validation failed; `errors` lists each field (Laravel's standard format) |
