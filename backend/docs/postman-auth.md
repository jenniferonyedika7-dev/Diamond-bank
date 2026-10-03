# Calling the auth API from Postman

The API uses **Sanctum SPA cookie authentication**: a session cookie plus a CSRF token, no bearer tokens.
Postman has to imitate the React app at `http://localhost:5173`, or Sanctum treats requests as stateless and ignores the session cookie.

Base URL: `http://localhost:8000`. Always use `localhost`, never `127.0.0.1`: cookies are set for `localhost` (`SESSION_DOMAIN=localhost`) and are not sent to `127.0.0.1`.

## 1. One-time setup

1. Start the backend: `php artisan serve` (serves on `http://localhost:8000`).
2. Create an environment (e.g. "Diamond Bank local") with a variable `base_url = http://localhost:8000`.
3. Use the Postman desktop app. Postman keeps the `laravel-session` cookie and sends it back to `localhost` automatically; the scripts below only deal with the CSRF token.
4. On the collection, open **Variables** and add `xsrf_token` (leave the value empty). The scripts read and write it.
5. On the collection, open **Scripts → Pre-request** and paste this. It runs before every request in the collection:

   ```js
   // Sanctum only treats requests from a stateful domain (SANCTUM_STATEFUL_DOMAINS) as SPA requests.
   pm.request.headers.upsert({ key: 'Accept', value: 'application/json' });
   pm.request.headers.upsert({ key: 'Origin', value: 'http://localhost:5173' });
   pm.request.headers.upsert({ key: 'Referer', value: 'http://localhost:5173/' });

   const xsrf = pm.collectionVariables.get('xsrf_token');
   if (xsrf) {
       pm.request.headers.upsert({ key: 'X-XSRF-TOKEN', value: xsrf });
   }
   ```

   | Header | Why |
   |---|---|
   | `Accept: application/json` | JSON errors instead of HTML or redirects |
   | `Origin` / `Referer: http://localhost:5173` | Without them Sanctum treats the request as stateless and ignores the session cookie |
   | `X-XSRF-TOKEN` | Laravel's CSRF check on every POST, PUT, PATCH and DELETE |

6. On the collection, open **Scripts → Post-response** and paste this. It runs after every response:

   ```js
   // Laravel sends a fresh XSRF-TOKEN cookie on responses. Keep the latest one.
   const setCookies = pm.response.headers.all()
       .filter(h => h.key.toLowerCase() === 'set-cookie')
       .map(h => h.value);

   for (const cookie of setCookies) {
       const match = cookie.match(/^XSRF-TOKEN=([^;]+)/);
       if (match) {
           // The cookie value is URL-encoded; the header must carry the decoded value.
           pm.collectionVariables.set('xsrf_token', decodeURIComponent(match[1]));
       }
   }
   ```

   **This handles token rotation.** Login and change-password regenerate the session, and logout invalidates it. Each of these sends a new `XSRF-TOKEN` cookie, so the old token stops working. The post-response script saves the new one straight away, and the next request sends it. You don't have to call `/sanctum/csrf-cookie` again after logging in.

## 2. Order of requests

### Step 1: get the CSRF cookie (always first)

```
GET {{base_url}}/sanctum/csrf-cookie
```

Expect **204 No Content**. The collection variable `xsrf_token` should now have a value, and **Cookies** (under Send) should show `laravel-session` for `localhost`.
You normally do this once. Repeat it if you get **419** after the session expires (120 minutes) or after restarting Postman with an empty `xsrf_token`.

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

Login regenerates the session, so the response carries a new `laravel-session` cookie and a new `XSRF-TOKEN`. The post-response script saves the new token to `xsrf_token`.

Possible responses:

| Status | Meaning |
|---|---|
| 200 | Logged in; `data` is the same as `/auth/me` |
| 401 | `Invalid username or password.` (same message whether or not the user exists) |
| 403 | `Your account is awaiting approval.` or `Your account is blocked. Please contact the bank.` |
| 429 | `Too many login attempts. Try again in N seconds.` See "Rate limits" below |
| 400 | No session: you skipped step 1, or the `Origin`/`Referer` headers are missing |

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

The session is invalidated. The response carries a new `XSRF-TOKEN`, which the post-response script saves, so you can log in again straight away.

## Rate limits on login

Only failed attempts count; checks run before the password is verified.

- **5 failures per minute** for the same user name from the same IP.
- **20 failures per hour** for the same user name from any IP.

User names are compared case-insensitively. Either limit returns the same 429 with a `Retry-After` header. The account itself is never locked or blocked. A successful login clears the per-IP counter only.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| **419** CSRF token mismatch | The token is missing. Check that the collection variable `xsrf_token` exists and has a value. If it's empty, run `GET /sanctum/csrf-cookie` and check that the post-response script is on the collection, not on a single request. |
| **404** and the route name in the message ends with a dot (e.g. `The route api/v1/auth/login. could not be found.`) | Typo in the URL: a trailing `.` (or other stray character) after the path. Remove it. |
| **401** on `/auth/me` right after a successful login | `Origin`/`Referer` are missing, so Sanctum ignores the session cookie. Check the collection pre-request script, and that the URL uses `localhost`, not `127.0.0.1`. |
| **400** "Login needs a session" | Same cause as the 401, on the login request itself. |
| **422** | Validation failed; `errors` lists each field (Laravel's standard format). |
