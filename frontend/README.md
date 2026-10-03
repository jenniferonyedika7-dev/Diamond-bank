# Diamond Bank frontend

React SPA for the Diamond Bank API. Vite + React (JavaScript), React Router, Tailwind CSS v4, axios.

Auth is Laravel Sanctum **SPA cookie auth**: a session cookie plus a CSRF token. No tokens or user data are stored in `localStorage`/`sessionStorage`; on load the app asks `GET /api/v1/auth/me` who is logged in.

## Run the backend and frontend together

Always use **`localhost`**, never `127.0.0.1`. The session cookies are set for `localhost` (`SESSION_DOMAIN=localhost`), and Sanctum only treats `localhost:5173` as the SPA (`SANCTUM_STATEFUL_DOMAINS`).

Terminal 1, backend (http://localhost:8000):

```bash
cd backend
php artisan serve
```

Terminal 2, frontend (http://localhost:5173):

```bash
cd frontend
cp .env.example .env   # first time only: VITE_API_URL=http://localhost:8000
npm install            # first time only
npm run dev
```

Open http://localhost:5173. The dev server is pinned to `localhost:5173` (`strictPort`). If that port is taken it fails instead of moving to another port, since the backend's CORS and Sanctum config expect 5173.

Other scripts: `npm run build` (production build to `dist/`), `npm run lint` (oxlint), `npm run preview`.

## Layout

```
src/
  lib/api.js          axios instance (withCredentials, withXSRFToken), ensureCsrf(), interceptors, error helpers
  lib/roles.js        dashboardPath(user), displayName(user), role labels
  lib/money.js        formatMoney(amount): GMD via Intl.NumberFormat('en-GM')
  auth/               AuthProvider (user, login, logout, refresh), useAuth, ProtectedRoute, PublicOnlyRoute
  components/         AppLayout (header), AuthLayout, FormField, Button, Alert, PasswordRules, spinners
  pages/              Login, RegisterCustomer, RegisterStaff, ChangePassword, Dashboard
```

How the API client behaves:

- Before login, register and change-password it calls `GET /sanctum/csrf-cookie`. axios reads the `XSRF-TOKEN` cookie fresh on every request, so the token rotation after login, password change and logout needs nothing extra.
- **419**: fetches a new CSRF cookie and retries the request once.
- **401** (other than a failed login) and **403 "Your account is not active."**: clears the user, and the route guards send them to `/login`.
- **403 with `data.must_change_password: true`**: flags the user, and the guards send them to `/change-password`.

### Backend behaviour the UI relies on

- Every response is `{ success, message, data }` **except 422**, which is Laravel's `{ message, errors: { field: [...] } }`.
- `/auth/me` is behind the password-changed check. So after a reload, a user who still must change their password gets a 403 with no name or role, and the header shows only the Logout button until they change it.
- `full_name` is only returned for employees (admin, staff). Customers are shown by `user_name`.
- 429 login lockout: the seconds are only in the message ("Try again in N seconds."). The `Retry-After` header isn't exposed via CORS, so the login page reads N from the message and counts down.

## Manual test checklist

Start with a fresh database (`php artisan migrate:fresh --seed` in `backend/`) so the admin still has `must_change_password` set and no branches exist. The admin credentials are `ADMIN_USERNAME` / `ADMIN_PASSWORD` in `backend/.env`.

- [ ] **Admin login → dashboard.** Sign in as the admin. You're sent to **/change-password** with "You must change your password before continuing." Enter the current password and a new one (8+ chars, letters and numbers). You land on **/admin**, and the header shows "System Administrator · Administrator".
- [ ] **Reload while signed in.** Reload /admin: a brief loading screen, then the dashboard again (no flash of the login page).
- [ ] **Wrong role area.** As admin, open http://localhost:5173/customer or /staff: you're redirected to /admin. Open /login: you're redirected to /admin.
- [ ] **Wrong password.** Log out, then sign in with a wrong password: "Invalid username or password."
- [ ] **Too many attempts** (optional). Six wrong passwords for the same user: "Too many login attempts. Try again in N seconds." The button stays disabled with a countdown.
- [ ] **Customer register, no branches.** Open /register (or "Open an account" on the login page): "Registration is not open yet. The bank has no branches set up." No form is shown.
- [ ] **Staff register.** Open /register/staff and submit the empty form: an error appears under each field. Fill it in correctly and submit: "Your account is awaiting admin approval." with a "Back to sign in" link.
- [ ] **Pending staff login refused.** Sign in as that staff user with the right password: "Your account is awaiting approval."
- [ ] **Duplicate registration.** Register staff again with the same username/email: 422 errors under those fields.
- [ ] **Logout.** Sign in as admin, click **Log out** (it shows "Signing out…"): you're on /login. Press the browser **Back** button: you stay on /login and the dashboard isn't shown. Typing /admin in the address bar also lands on /login.
- [ ] **Keyboard and screen reader.** Tab through the login and register forms: every field has a visible focus ring and a label, and error text is read with its field.
- [ ] **Mobile.** In devtools at ~375px width, the forms are single-column with no sideways scrolling.

If something fails with 401 right after logging in, or 400 "Login needs a session", check that both the backend and frontend URLs use `localhost` and that `backend/.env` has `SESSION_DOMAIN=localhost` and `SANCTUM_STATEFUL_DOMAINS=localhost:5173`.
