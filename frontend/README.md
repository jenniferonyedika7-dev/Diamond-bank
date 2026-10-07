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
  admin/pages/        Overview, BankSettings, Branches, AccountTypes, CardTypes, LoanTypes, Departments, Customers, Staff, Loans, LoanDetail
  staff/              StaffShell (loads the branch for the header), StaffLayout (sidebar)
  staff/pages/        Dashboard, Customers, CustomerDetail, AccountDetail, Cards, Loans, LoanDetail
  customer/           CustomerShell (loads /customer/overview for the area), CustomerLayout (sidebar), KycNotice, AccountCards
  customer/pages/     Overview, Accounts, AccountDetail, Transfer (3 steps), Cards, Loans, ApplyLoan (4 steps), LoanDetail, Profile
  shared/             AuditLogPage (/staff/audit-log and /admin/audit-log)
  shared/loans/       LoanReviewList and LoanReviewDetail (staff and admin loan pages), ScheduleTable, LoanParts
  lib/loans.js        loan labels, status tabs and the TIN/phone formats (no money arithmetic)
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
- Loans: the browser never calculates money. The quote, instalment, totals, schedule and affordability all come from the API. The apply form re-quotes 600 ms after the terms change, limited to 30 quotes a minute per customer (429 is shown in the quote panel).
- Every loan needs two approvals: branch staff (after recording the four checks) and then an admin, whose approval pays the loan out through `sp_disburse_loan`. The same person can't make both; the procedure refuses with a 422 that the confirm dialog shows as-is.
- Loan lists mask the TIN (`******789`); only the staff and admin loan detail shows it in full. Instalment statuses Overdue, Due and Upcoming are derived by the API from the due date (`display_status`), never stored.
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

## Manual test checklist: cards, admin customers and usernames (Phase F1)

**Migrate first.** Run this in `backend/`, against `bank_db`:
1. `php artisan migrate --pretend`: check the SQL.
2. `php artisan migrate`: applies 3 forward migrations.

What the migrations do:
- `bank_card` gains request columns and an encrypted card number. This refuses to run if `bank_card` has rows.
- Unused "credit" card types are removed.
- Account type, card type and department names become Title Case.

Never run `migrate:fresh` on `bank_db`. Read "Card data and APP_KEY" in `backend/README.md` before going further.

**Setup**
- [ ] As admin, **Card types** → add `credit gold` → "Only debit cards are supported." Add `debit silver` → it is saved as **Debit Silver**.

**Customer: request and block (testcustomer)**
- [ ] The sidebar has **Cards** → "You have no cards yet." and a "Request a debit card" form. The account list shows only active accounts without a card.
- [ ] Request a Debit Classic card on DB0010000001 → "Card requested…". The tile shows **Requested** and "Number given on issue". The form now says each active account already has a card or a pending request.
- [ ] As teststaff, freeze DB0010000001. As testcustomer, reload Cards → that account isn't offered. Unfreeze it.

**Staff: issue, reject, unblock (teststaff)**
- [ ] **Cards** → the Requests tab lists testcustomer's request. **Issue** → "Card issued: **** **** **** 1234." It moves to the Active tab with an expiry at the end of the month, 3 years out.
- [ ] As testcustomer, the tile shows the masked number and expiry. **Block card** → confirm → Blocked. Request a replacement on the same account → accepted (a blocked card doesn't count).
- [ ] On an active card, **Show number** → enter a wrong password → "The password is incorrect." under the field. The correct password shows the full number in 4 groups, with **Copy** and **Hide**.
  - It hides itself after 30 seconds, when you switch to another tab and back, and when you leave the Cards page.
  - DevTools → Network: the `reveal` response has `Cache-Control: no-store`.
  - 5 wrong passwords in a minute → the next attempt gets "Too many attempts…". This limit is shared with transfers.
- [ ] As teststaff, the Blocked tab → **Unblock** the old card → it works (no other active card yet). The Requests tab → **Issue** the replacement → "This account already has an active card."
- [ ] Reject the replacement with a reason → as testcustomer, the tile shows **Rejected** and the reason.
- [ ] The customer detail page (staff) has a **Cards** section with masked numbers.
- [ ] A staff member at another branch doesn't see these cards on their Cards page.

**Admin: customers and usernames**
- [ ] **Customers** → search, KYC and branch filters. Open testcustomer → profile, login and an accounts table (number, type, branch, status, balance). There are no edit, freeze or block buttons.
- [ ] Keep testcustomer signed in in another browser. As admin, **Change username** → `testcustomer2` → "Username changed from testcustomer to testcustomer2." Reload the customer's browser → still signed in, and the header shows the new name.
- [ ] Sign out the customer. `testcustomer` no longer signs in; `testcustomer2` does with the same password.
- [ ] Try a taken username or `has space` → the error is shown under the field.
- [ ] **Staff** → **Change username** on any staff card works the same way. There is no password option anywhere in the admin area.

**Database check (MySQL Workbench)**
- [ ] `SELECT bank_card_id, card_number, last4, status FROM bank_card;`: `card_number` is ciphertext (`eyJpdiI6…`), never the 16 digits. There is no CVV column.
- [ ] `SELECT action_type, details FROM audit_log WHERE action_type LIKE 'CARD_%' OR action_type = 'USERNAME_CHANGED' ORDER BY audit_log_id DESC;` lists every step. USERNAME_CHANGED shows the old → new name. `CARD_NUMBER_REVEALED` and `CARD_REVEAL_PASSWORD_FAILED` rows hold last4 only. No row contains a full card number or a password.

**Mobile (~375px)**
- [ ] The card tiles stack, the staff card rows wrap their buttons, and the admin accounts table scrolls sideways inside its card.

## Manual test checklist: loans (Phase F2a-2)

**No migration in this phase.** The F2a-1 migrations must already be applied to `bank_db`. Never run `migrate:fresh` on `bank_db`. You need testcustomer (KYC verified, with an active account), teststaff at that account's branch, the admin, and a second staff member at another branch.

**Loan types (admin)**
- [ ] **Loan types** → add `personal` with rate `0` → "The interest rate must be greater than zero." under the field. Rate `12` → saved as **Personal**, 12.00%.
- [ ] Add `Business` at 15%. Edit its rate to 14.5% → saved.

**Apply, Monthly plan (testcustomer)**
- [ ] The sidebar has **Loans** → "You have no loans yet." and **Apply for a loan**.
- [ ] Step 1: pick Personal (the option shows its rate), the account, 10000, 12 months, Monthly instalments. Each plan has a one-line explanation. The quote shows the monthly instalment, 12 payments, total interest and total repayable. Change the amount to 500 → the minimum-loan message appears under Amount and Next is refused.
- [ ] Step 2: TIN `123` → "The TIN must be 8 to 15 digits." Choose Employed → only employer name, workplace address, employer phone and job title appear. Switch to Content creator → only platform and account handle. Your profile phone and email are shown read-only with the "ask your branch" hint.
- [ ] Step 3: the note says the bank will contact the guarantor. Fill in a guarantor.
- [ ] Back to step 1 and forward again: everything you entered is still there.
- [ ] Step 4: every value is listed with Edit links, plus the quote and a 12-row schedule with "(est.)" dates. Submit stays disabled until the confirmation box is ticked.
- [ ] Using DevTools or a second tab, make the guarantor's phone your own profile phone and submit → you land on step 3 with "A guarantor can't be yourself…" under the phone.
- [ ] Submit → back on Loans with "Loan application submitted…". The loan shows **Pending** with a Cancel button. **Apply for a loan** is gone, and a note explains you can have one loan at a time.

**Staff checks and first approval (teststaff)**
- [ ] **Loans** → the Pending tab (with counts) lists the loan: customer, amount, plan, term, applied date, TIN as `******789`, and the affordability %. If you entered a low income, an **Above 33%** badge shows.
- [ ] Open it. You see the full TIN, the employment fields, the contact snapshot and the guarantor. The affordability panel says it's a warning, not a rule. The statement lists the last 6 months, paged.
- [ ] **Approve** is disabled and lists the four missing checks. Record each check with a note, then update one → the newer note and time show.
- [ ] **Approve** → confirm → "Loan approved. It now needs an administrator's approval." The loan is now **Awaiting admin**, and the Decisions panel shows your name and time.

**Admin approval and disbursement**
- [ ] **Loans** opens on **Awaiting admin**, and each row shows its branch. The detail shows a read-only checklist and the staff approver with the time.
- [ ] Note testcustomer's account balance. **Approve and disburse** → the dialog names the amount and the account → "Loan approved. GMD 10,000.00 was paid into account …".
- [ ] As testcustomer, the account balance went up by the loan amount, and the history shows a **Loan disbursement** transaction.
- [ ] The loan detail shows **Active**, both approval dates and the schedule: instalment 1 **Due** and the rest **Upcoming**. There are no payment buttons.

**Single plan, rejection, cancellation**
- [ ] (After closing or rejecting the open loan, or as another customer.) Apply with **Single payment at the end** for 6 months → the quote shows one payment equal to the total repayable, and the review schedule has a single row.
- [ ] As teststaff, **Reject** it with a reason → as the customer, Loans shows **Rejected** with the reason, and **Apply for a loan** is back.
- [ ] Apply again. Staff record the checks and approve → **Awaiting admin**. The customer **Cancel**s → **Cancelled**. As admin, the loan is under the Cancelled tab with no actions. If you had it open, Approve → "This loan is no longer awaiting approval."

**Scoping and audit**
- [ ] Staff at another branch: the loan isn't on their Loans page, and opening `/staff/loans/<id>` shows "Loan not found."
- [ ] **Audit log** (admin) shows LOAN_TYPE_CREATED, LOAN_TYPE_UPDATED, LOAN_APPLIED, LOAN_CHECK_RECORDED (one per record or update, with the before note on updates), LOAN_APPROVED_STAFF, LOAN_APPROVED_ADMIN (the disbursement), LOAN_REJECTED and LOAN_CANCELLED.
