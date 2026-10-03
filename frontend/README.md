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
  lib/format.js       formatPercent(rate), formatDate(value), todayIso()
  auth/               AuthProvider (user, login, logout, refresh), useAuth, ProtectedRoute, PublicOnlyRoute
  components/         AppLayout (header), AuthLayout, FormField, Button, Alert, Modal, ConfirmDialog, Pagination, …
  pages/              Login, RegisterCustomer, RegisterStaff, ChangePassword, Dashboard (staff/customer placeholder)
  admin/              AdminLayout (sidebar), ResourcePage (table + form + delete for one resource), useApiList
  admin/pages/        Overview, BankSettings, Branches, AccountTypes, CardTypes, Departments, Staff
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
- 429 login lockout: the login page counts down from the `Retry-After` header (exposed via CORS), falling back to N in "Try again in N seconds.".
- Admin lists: `/admin/branches` and `/admin/staff` return `data: { items, pagination }`; the other lists return a plain array. Deleting something that is in use returns 409 with a message naming what uses it.

## Manual test checklist: auth (Phase B)

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

## Manual test checklist: admin area (Phase C)

Starts from the current state of `bank_db`: only the admin (password already changed) and one PENDING staff user `teststaff`, no bank and no branches. Don't reset the database; every step below works on that data. Keep a private window open for the staff user.

**Overview and setup order**
- [ ] Sign in as the admin → **/admin** shows the sidebar (Overview, Bank settings, Branches, Account types, Card types, Departments, Staff), a navy **"Set up your bank first"** banner, **"1 staff member awaiting approval"**, Pending staff 1, Branches 0.
- [ ] **Staff → Pending** → **Approve** on teststaff → the dialog says "Add a branch first…" with a link to Branches (no branches yet). Close it.
- [ ] **Branches** → "No branches yet. Add your first branch." → **Add branch** → fill it in → Save → "Set up the bank details first." with a **Go to Bank settings** link.

**Bank settings**
- [ ] The heading says "Set up your bank". Enter SWIFT `ABC` → error under SWIFT code. A future established date → error under the date.
- [ ] Valid data (e.g. Diamond Bank / `dbnkgmgm` / 2001-05-10) → "Bank details saved."; the SWIFT code shows as `DBNKGMGM`; the heading becomes "Bank details".
- [ ] Change the name → "Bank details updated." Reload → the change is kept. Overview no longer shows the banner.

**Branches**
- [ ] Add `Banjul Main` with code `bjl001` → "Branch created."; the table shows `BJL001`.
- [ ] Add another branch with code `BJL001` → error under Branch code. Use `SRK01` instead → created.
- [ ] Search `srk` → only that branch. Edit it (change the phone) → "Branch updated."

**Reference data**
- [ ] **Departments**: add "Operations" and "Customer Service".
- [ ] **Account types**: interest rate `101` → error; `3.555` → error; SAVINGS / 3.5 / 100 → the table shows **3.50%** and **GMD 100.00**.
- [ ] **Card types**: daily limit `0` → error; DEBIT / 5000 → shows **GMD 5,000.00**. Add DEBIT again → error under Name.

**Staff approval**
- [ ] **Staff → Pending → Approve** teststaff: try submitting without a branch → error under Branch. Pick Banjul Main + Operations → "teststaff has been approved." The Pending tab is now empty; **Active** shows teststaff with branch and department.
- [ ] In the private window, sign in as teststaff → lands on **/staff**.

**Block and unblock**
- [ ] As admin, **Active → Block** teststaff: the Block button stays disabled until you type a reason; enter one → "teststaff has been blocked."
- [ ] In teststaff's window, reload → back on /login. Signing in shows "Your account is blocked. Please contact the bank."
- [ ] **Blocked → Unblock** → confirm → "teststaff has been unblocked." teststaff can sign in again.

**Reject**
- [ ] In the private window (signed out), register a second staff user at **/register/staff**.
- [ ] As admin, **Pending → Reject** them with a reason → "…'s registration has been rejected." They appear under **Blocked** with a red **Rejected** badge and no Unblock button. Signing in as them shows the blocked message.

**Deletes refused when in use**
- [ ] **Branches → Delete** Banjul Main → the dialog shows "This branch can't be deleted: it is used by 1 employee assignment." Close.
- [ ] **Departments → Delete** Operations → refused (1 employee assignment). Delete Customer Service → "Department deleted."
- [ ] Delete the SAVINGS account type and DEBIT card type → both deleted (nothing uses them yet). Add them back if you want them.

**Access and layout**
- [ ] As teststaff, open http://localhost:5173/admin/branches → redirected to /staff.
- [ ] At ~375px width the sidebar becomes a **Menu** button that opens the nav and closes after you pick a page; tables become cards; dialogs fit the screen and close with Escape.
- [ ] The browser tab shows the navy/gold diamond favicon.
- [ ] Sign out, then enter a wrong password 6 times for the same user → the countdown runs (now from the Retry-After header).
- [ ] In MySQL Workbench: `SELECT action_type, record_id, details FROM audit_log ORDER BY audit_log_id DESC;` shows BANK_CREATED, BANK_UPDATED, BRANCH_CREATED/UPDATED, DEPARTMENT_*, ACCOUNT_TYPE_*, CARD_TYPE_*, STAFF_APPROVED, STAFF_BLOCKED (with reason), STAFF_UNBLOCKED, STAFF_REJECTED (with reason), each with before/after values.
