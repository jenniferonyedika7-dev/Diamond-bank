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
  components/         AppLayout (header), SideNavLayout, FormField, Button, Alert, Modal, ConfirmDialog, ReasonDialog, TransactionList, StatusBadges, …
  pages/              Login, RegisterCustomer, RegisterStaff, ChangePassword, Dashboard (staff/customer placeholder)
  lib/useApiList.js   list loading (plain or paginated) + useDebounced
  admin/              AdminLayout (sidebar), ResourcePage (table + form + delete for one resource)
  admin/pages/        Overview, BankSettings, Branches, AccountTypes, CardTypes, Departments, Staff
  staff/              StaffShell (loads the branch for the header), StaffLayout (sidebar)
  staff/pages/        Dashboard, Customers, CustomerDetail, AccountDetail
  customer/           CustomerShell (loads /customer/overview for the area), CustomerLayout (sidebar), KycNotice, AccountCards
  customer/pages/     Overview, Accounts, AccountDetail, Transfer (3 steps), Profile
  shared/             AuditLogPage (/staff/audit-log and /admin/audit-log)
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
- Staff endpoints need a current branch (`employee_branch_lnk.end_date IS NULL`); without one they return 403 "You are not assigned to a branch. Contact the administrator." The audit log is the exception for admins.
- Money moves only through the stored procedures. When one refuses (SQLSTATE 45000) the API returns 422 in the `{ success, message, data }` envelope with the procedure's message, which the UI shows as-is.
- Customer endpoints are scoped to the signed-in customer. Someone else's account number gets the same 404 "Account not found." as a non-existent one. Transfer lookup gives one identical 422 for missing, frozen and closed accounts, and only ever returns a masked name ("A*** J***").
- Transfers need the customer's password and are limited to 5 attempts per minute (lookups to 10). Over the limit → 429 with `Retry-After`.
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

## Manual test checklist: staff area (Phase D)

**First, in `backend/`:** `php artisan migrate`. It adds only the `sp_deposit` and `sp_withdraw` procedures (no table changes). `php artisan migrate:status` should then show `2026_10_04_000001_create_deposit_withdraw_procedures` as Ran.

Starts from the current `bank_db`: Diamond Bank, Brikama Branch (BRK001), account types Savings and Current, card types, the Operations department, the admin, and ACTIVE `teststaff` at Brikama Branch. No customers yet. Note the Savings minimum balance (shown in Admin → Account types); the steps below assume GMD 100.00, so adjust the amounts if yours differs.

**Setup**
- [ ] In a private window, register a customer at **/register** (Brikama Branch). Leave that window open, signed in as the customer.

**Dashboard and header**
- [ ] Sign in as **teststaff** → lands on **/staff**. Under the brand the header shows **Brikama Branch · BRK001** (plus the department, if one was assigned). The sidebar shows Dashboard, Customers and Audit log.
- [ ] The dashboard shows **"1 customer awaiting KYC"** and Awaiting KYC 1.
- [ ] Quick search: type the customer's surname → the Customers page (All tab) with that customer. Type `DB0019999999` → the account page says "The requested record was not found."

**Customer profile and KYC**
- [ ] **Customers → Pending KYC** → open the customer. **Open account** is disabled, with "Accounts can be opened once the customer's KYC is verified."
- [ ] **Edit**: change the phone → "Customer details updated." Set the date of birth to 10 years ago → error under Date of birth.
- [ ] **Verify** → confirm → "Customer verified."; the KYC card shows "Verified by … on …". Edit again → the National ID field is locked, with a note explaining why.

**Open an account**
- [ ] **Open account**: the type list shows "Savings: minimum GMD 100.00". Pick Savings, enter `50` → "The initial deposit is below the minimum balance for this account type."
- [ ] Enter `1000` → you land on the new account page (number like `DB…`) with "Account DB… opened." and a balance of **GMD 1,000.00**. The history shows the initial deposit, at Brikama Branch.

**Deposit, withdraw**
- [ ] **Deposit** 500 with a description → the dialog shows **New balance GMD 1,500.00**; Done → the balance and history update (green +GMD 500.00).
- [ ] **Withdraw** 1450 → "Insufficient funds: this withdrawal would take the account below its minimum balance." The balance is unchanged after closing.
- [ ] **Withdraw** 100 → New balance GMD 1,400.00 (red −GMD 100.00 in the history).
- [ ] **Deposit** `12.345` → "Amounts can have at most 2 decimal places."

**Freeze**
- [ ] **Freeze**: the button stays disabled until a reason is typed → "Account frozen."; the status badge shows Frozen, and Deposit/Withdraw are disabled.
- [ ] **Unfreeze** → "Account unfrozen."; deposits work again.

**Customer login block**
- [ ] Back on the customer page, **Online login → Block login** with a reason → "Customer login blocked."
- [ ] In the customer's private window, reload → back on /login. Signing in shows "Your account is blocked. Please contact the bank."
- [ ] **Unblock login** → the customer can sign in again.

**Audit log**
- [ ] **Staff → Audit log**: newest first, showing CUSTOMER_UPDATED, CUSTOMER_VERIFIED, ACCOUNT_OPENED, DEPOSIT, WITHDRAWAL, ACCOUNT_FROZEN/UNFROZEN and CUSTOMER_BLOCKED/UNBLOCKED, each by teststaff (staff).
- [ ] Filter Action = CUSTOMER_VERIFIED → one row. **Details** expands the JSON (`verified_by`, `verified_at`). Set From to tomorrow → "No entries match these filters." Clear filters.
- [ ] Sign in as the admin → the sidebar now has **Audit log**, showing the same entries (plus the admin's own).
- [ ] As admin, open http://localhost:5173/staff → redirected to /admin. As teststaff, open /admin → redirected to /staff.

**Database check (MySQL Workbench)**
- [ ] `SELECT t.transaction_id, tt.type_name, t.amount, t.balance_after, t.branch_id, t.employee_id, t.channel FROM transactions t JOIN transaction_type tt USING (transaction_type_id) ORDER BY t.transaction_id;` → every row has Brikama's branch_id, teststaff's employee_id and channel BRANCH, and the balance_after values chain correctly.

**Mobile (~375px)**
- [ ] The sidebar becomes a Menu button; the transaction history becomes cards; dialogs fit the screen and close with Escape.

## Manual test checklist: customer area (Phase E)

No migration is needed for this phase. Starts from the current `bank_db`: Diamond Bank, Brikama Branch, Savings (minimum 500) and Current (minimum 1000), the admin, ACTIVE `teststaff`, and VERIFIED **Test Customer** (`testcustomer`) with Savings account **DB0010000001**.

**Setup (as teststaff, in a private window)**
- [ ] Register a second customer at **/register**. Before verifying them, sign in as them in another window and check the PENDING notices (next section).
- [ ] As teststaff: verify the second customer and open a **Current** account for them with 2000 (it gets the next number, e.g. **DB0010000002**). Make sure DB0010000001 has at least 1500 (deposit if needed).

**KYC notices (second customer, before verification)**
- [ ] Sign in → **/customer** with the sidebar Overview, Accounts, Transfer, Profile, Change password. Overview, Accounts and Transfer show only "Your identity is being verified. Visit Brikama Branch with your national ID." with no balances or transfer form. Profile still works.
- [ ] After teststaff verifies them but before the account is opened, reload → "Visit a branch to open your first account."

**Overview and account (testcustomer)**
- [ ] Sign in as `testcustomer` → the overview shows the total balance, a Savings card for DB0010000001 with its balance and Active badge, and up to 5 recent transactions.
- [ ] Open the account → balance, "Minimum balance D 500.00", interest, branch; the history has credits in green and debits in red. Filter Type = Deposits, then a From/To range with no activity → "No transactions match these filters." Clear filters.
- [ ] In the address bar, open `/customer/accounts/DB0010000002` (the other customer's) → "Account not found.", the same as `/customer/accounts/DB0019999999`.

**Transfer: lookup**
- [ ] **Transfer**: From shows DB0010000001 with "You can send up to …" (balance minus 500).
- [ ] Recipient `DB0019999999`, amount 200 → Continue → "This account can't receive transfers. Check the number and try again." under the recipient field.
- [ ] Recipient = your own number → "You can't transfer to the same account."
- [ ] Amount `0`, or `12.345` → the amount error, and no request is sent.

**Transfer: review, password, result**
- [ ] Recipient `DB0010000002`, amount 200, description "Test" → Continue → **"Send D 200.00 to X*** Y*** (DB0010000002)"**: the second customer's initials only, never their full name.
- [ ] Wrong password → "The password is incorrect." under the field; still on the review step. Balances are unchanged (check the overview after).
- [ ] Back → change the amount to more than "you can send" (e.g. balance − 400) → Continue → correct password → Step 3 shows "Insufficient funds: this transfer would take the account below its minimum balance." and "No money was moved." → Back to edit.
- [ ] Amount 200, correct password, then **double-click Send money** → the button shows "Sending…" and is disabled; **only one** transfer happens. Step 3: "Transfer complete. You sent D 200.00 to X*** Y*** (DB0010000002)" and the new balance.
- [ ] The overview and account history show **Transfer out −D 200.00** (channel ONLINE). Sign in as the second customer → **Transfer in +D 200.00** on DB0010000002.

**Frozen accounts**
- [ ] As teststaff, **freeze DB0010000002**. As testcustomer, a lookup of DB0010000002 → the same "can't receive" message as the made-up number.
- [ ] Unfreeze it and **freeze DB0010000001** instead. As testcustomer: the account card shows Frozen, the account page has no "Transfer from this account" button, and Transfer shows "None of your accounts can send money right now…" (the option is listed as "(frozen)" and disabled). Unfreeze afterwards.

**Rate limit**
- [ ] On the review step, submit a wrong password 5 times; the 6th attempt within a minute → "Too many attempts. Try again in N seconds."

**Profile and password**
- [ ] **Profile**: read-only details and "To change your details, visit your branch." There are no edit controls.
- [ ] **Change password** (sidebar) works inside the customer area and returns to the overview.

**Access**
- [ ] As testcustomer, open `/staff` or `/admin` → redirected to `/customer`. As teststaff, open `/customer` → redirected to `/staff`.

**Login page**
- [ ] The link reads **"New customer? Register online"**.

**Database check (MySQL Workbench)**
- [ ] `SELECT action_type, user_id, record_id, details FROM audit_log WHERE action_type IN ('TRANSFER', 'TRANSFER_PASSWORD_FAILED') ORDER BY audit_log_id DESC;` shows one TRANSFER for the successful transfer and TRANSFER_PASSWORD_FAILED rows for the wrong passwords. None of them contain a password.
- [ ] `SELECT * FROM transfer ORDER BY transfer_id DESC LIMIT 3;` shows exactly one new COMPLETED row of 200.00 (the double-click didn't create two).

**Mobile (~375px)**
- [ ] The sidebar becomes a Menu button; account cards stack; the transfer steps and history cards fit the screen.
