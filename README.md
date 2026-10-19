# Diamond Bank

Diamond Bank is a full banking web application for a fictional Gambian bank. Customers bank online, staff serve customers at their branch, and an administrator runs the bank. The money operations (opening accounts, transfers, cash deposits and withdrawals, loan payouts and loan repayments) are MySQL stored procedures, so the database itself enforces the banking rules.

Built by Jennifer Onyedika.

## Contents

1. [Features by role](#features-by-role)
2. [Tech stack](#tech-stack)
3. [Database](#database)
4. [Security](#security)
5. [Business rules](#business-rules)
6. [Installation](#installation)
7. [Configuration](#configuration)
8. [Running the tests](#running-the-tests)
9. [Demo notes](#demo-notes)
10. [Future work](#future-work)

## Features by role

There are three roles: customer, staff and admin. Each role has its own area in the web app and its own part of the API.

### Customer

- **Register online** on the *Open an account* page: personal details, home branch, phone and a username and password. The customer can sign in straight away, and their KYC (identity check) starts as pending.
- **Overview**: accounts, balances and recent activity.
- **Accounts**: each account's details and its transaction history.
- **Transfer**: send money from one of their accounts to any active account. They look up the destination account first and confirm with their password. The page shows how much of the day's transfer limit is left.
- **Cards**:
  - Request a debit card for an account; the customer's KYC must be verified first. An account can have one live card.
  - Block a lost card.
  - See the full card number after entering their password.
- **Loans**:
  - Apply in four steps: loan terms with a live quote, employment and income, guarantor, and review. The customer needs a verified KYC and can have only one open loan at a time.
  - Cancel an application until it is decided.
  - Follow its progress and see the repayment schedule.
  - Pay online from one of their accounts: either the next instalment or the whole loan early. A payment needs their password.
- **Profile**: view their personal details.
- **Change password**.

### Staff

Staff register on the public staff sign-up page, and an admin must approve them before they can sign in. Each staff member works at one current branch.

- **Dashboard**: a summary for their branch.
- **Customers**: search and view customers at every branch. At their own branch they can also:
  - edit a customer's details;
  - verify or reject KYC;
  - block or unblock a customer's login;
  - raise an access request for a customer locked out by wrong passwords.
- **Accounts**:
  - Open an account for a customer, with an initial deposit.
  - Record cash deposits and withdrawals at any branch.
  - Freeze and unfreeze accounts at their own branch.
- **Cards**: issue or reject card requests on accounts at their branch, and unblock blocked cards.
- **Loans** at their branch:
  - Record the four checks: bank statement reviewed, employment verified, guarantor contacted, and guarantor's occupation verified.
  - Make the first approval or reject.
  - Record cash repayments.
- **Audit log** for their branch: actions by staff working at the branch that day, and by customers whose home branch it is.

### Admin

- **Overview**: bank-wide counts (for example users by status and customers by KYC status).
- **Bank settings**: the bank's name, SWIFT code and date established.
- **Branches**: create, edit and delete.
- **Account types**: create, edit and delete. Each type has a minimum balance and a daily transfer limit.
- **Card types**: create, edit and delete debit card types, each with a daily limit.
- **Loan types**: create, edit and delete loan types, each with an annual interest rate.
- **Departments**: create, edit and delete.
- **Customers**: a read-only view of every customer, plus changing a customer's username.
- **Loans**: the final (second) approval or rejection of loans from every branch. Approving pays the loan into the customer's account.
- **Staff**:
  - Approve a registration, assigning a branch and optionally a department, or reject it.
  - Block and unblock staff.
  - Change a staff member's username.
  - Restore access for a staff member locked out by wrong passwords.
- **Access requests**: approve or reject the customer access requests raised by staff.
- **Audit log**: every row.

## Tech stack

| Part | Technology | Version (from `composer.json` / `package.json`) |
|---|---|---|
| Backend | PHP | ^8.3 |
| | Laravel | ^13.17 |
| | Laravel Sanctum (SPA cookie authentication) | ^4.3 |
| | Pest (tests) | ^4.7 |
| Frontend | React | ^19.2.8 |
| | React Router | ^8.4.0 |
| | Vite | ^8.3.0 |
| | Tailwind CSS | ^4.3.3 |
| | axios | ^1.20.0 |
| | oxlint | ^1.81.0 |
| Database | MySQL | 8 |

The frontend is a single-page app that talks to the Laravel API under `/api/v1`. Authentication uses Sanctum's session cookies with CSRF protection, not API tokens.

## Database

The application database is `bank_db`. The tests use a separate database, `bank_db_testing`. Table and column names are lowercase, and the schema is built entirely by Laravel migrations.

### Tables

| Table | Holds |
|---|---|
| `bank` | The bank's details (one row). |
| `branch` | Branches: name, code, address and phone. |
| `employee` | Staff and admin personal details. |
| `department` | Departments. |
| `employee_branch_lnk` | Which branch each employee works at, with start and end dates. |
| `employee_department_lnk` | Which department each employee is in, with start and end dates. |
| `customer` | Customer personal details, home branch and KYC status. |
| `customer_address` | Customer addresses. |
| `account_type` | Account types, each with a minimum balance and a daily transfer limit. |
| `account` | Accounts: number, type, branch, balance, currency and status. |
| `transaction_type` | Kinds of transaction (deposit, withdrawal, transfer in and out, loan payout, loan payment). |
| `transfer` | Transfers between two accounts. |
| `transactions` | Every movement on an account, with the balance after it. |
| `card_type` | Debit card types and their daily limit. |
| `bank_card` | Debit cards and card requests. Numbers are stored encrypted, with the last four digits kept separately. |
| `loan_type` | Loan types and their annual interest rate. |
| `loan_application` | The income and employment details given with a loan application. |
| `loan_guarantor` | Each loan's guarantor. |
| `loan_verification` | The four staff checks recorded for a loan. |
| `loan` | Loans: amount, rate, term, repayment plan, totals, status and both approvals. |
| `loan_instalment` | Each loan's repayment schedule. |
| `loan_payment` | Loan payments, online or in cash. |
| `role` | The three roles: customer, staff and admin. |
| `users` | Logins. Each belongs to either a customer or an employee. |
| `audit_log` | Who did what, when, and to which record. |
| `access_request` | Requests to reopen a customer login locked by wrong passwords. |
| `demo_sms_outbox` | Simulated text messages (see [Demo notes](#demo-notes)). |

Laravel also creates its own `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` and `failed_jobs` tables.

### Stored procedures and functions

| Name | What it does |
|---|---|
| `sp_open_account` | Opens an account for a customer with an initial deposit, which must be at least the account type's minimum balance. It generates the account number and records the deposit. |
| `sp_transfer_funds` | Moves money between two accounts. It checks both accounts are active and use the same currency, that the sender owns the source account (for customers), the minimum balance, and the daily transfer limit. It writes the transfer and both transaction rows. |
| `sp_deposit` | Records a cash deposit at the staff member's current branch. |
| `sp_withdraw` | Records a cash withdrawal at the staff member's current branch. It refuses one that would take the account below its minimum balance. |
| `sp_disburse_loan` | The admin's final approval. It re-checks every row of the repayment schedule against the loan, saves the schedule, pays the loan into the customer's account and makes the loan active. |
| `sp_repay_loan` | Records one loan payment (the next instalment or an early payoff), online from an account or in cash at the branch. It works out the amount owed itself and closes the loan when nothing is left unpaid. |
| `fn_transfer_day_start` | Returns the start of the bank's current day (midnight, Africa/Banjul). |
| `fn_transferred_today` | Returns the total of today's completed transfers out of an account. It is used to show the remaining limit. |

Each procedure runs its own transaction and refuses bad input with a clear error message (SQLSTATE `45000`). A few cases have their own codes:
- `45001`: the loan's status, or the amount owed, changed while the customer was looking at it.
- `45002`: the transfer is over the daily limit.

### Rules enforced in the database

- **Minimum balance:**
  - Transfers and withdrawals cannot take an account below its type's minimum balance.
  - A new account's initial deposit must be at least that minimum.
  - A CHECK constraint stops any balance going below zero.
- **Daily transfer limit:** inside `sp_transfer_funds`, the total transferred out of the source account today plus the new amount must not exceed its account type's `daily_transfer_limit`.
- **Row locking:**
  - The procedures lock the rows they change with `SELECT … FOR UPDATE`, so two operations on the same account cannot interleave.
  - A transfer locks its two accounts in ascending `account_id` order, so two opposite transfers cannot deadlock.
  - Today's transfer total for the limit is read with `FOR SHARE`.
- **Amounts:** amounts must be greater than zero and have at most two decimal places. Procedures take amounts as `DECIMAL(20,6)`, so extra decimals are refused rather than silently rounded.
- **CHECK constraints** on balances, transfer amounts, the two accounts of a transfer being different, loan totals and terms, instalment and payment amounts, interest rates, the daily transfer limit, and that each login belongs to exactly one customer or employee.

## Security

- **Wrong-password lockout.** One count per user is shared by the login page and the in-app password checks (transfers, showing a card number, loan payments and changing a password):
  - 5 wrong passwords: locked for 1 minute.
  - The next wrong password after that lock: locked for 2 minutes.
  - The next wrong password after that: the account is **blocked**, and all its sessions end.
  - A correct password clears the count.
  - The count is forgotten 24 hours after the last wrong password.
  - Admins get repeated 2-minute locks but are never blocked.

  Only staff can reopen a blocked customer, through an access request that an admin approves. Only an admin can reopen a blocked staff member, using *Restore access*. The 1-minute, 2-minute and 24-hour values are configurable (see [Configuration](#configuration)).

  Separately, the login page allows at most 30 failed attempts a minute from one IP address.
- **Idle logout.** After 5 minutes with no clicks, typing or navigation (configurable), the server ends the session. Background requests do not count as activity. A warning with a countdown appears one minute before. All open tabs share the timer, and all log out together.
- **Daily transfer limit.** This is enforced inside `sp_transfer_funds`, not only in the API (see [Database](#rules-enforced-in-the-database)).
- **Maker-checker loan approval.** Every loan needs two approvals:
  - First, staff at the loan's branch record the four checks and approve.
  - Then an admin gives the final approval, and only that pays the loan out.
- **Staff branch scoping:**
  - Staff can only change customers at their own branch: edit details, KYC, blocking and access requests. Freezing accounts and handling cards and loans are also limited to their branch.
  - Viewing customers and cash deposits and withdrawals are allowed at every branch.
  - Staff without a current branch are refused.
- **Role checks on every API request.** Each part of the API (customer, staff, admin) is behind a role check. Customers only ever see their own data; another customer's loan or card returns "not found".
- **Audit log.** Sign-ins, failed sign-ins, lockouts and every change to money, customers, staff, cards, loans and settings are recorded with the user, IP address and time. Staff see their branch's rows; the admin sees all.
- **Masked card numbers:**
  - Card numbers are stored encrypted and shown masked everywhere.
  - Only the card's owner can see the full number, after entering their password.
  - The audit log records only the last four digits.
- **Masked demo SMS codes.** In the demo SMS outbox, a temporary password is shown only while it can still be used. Once it has been used, replaced by a newer one or has expired, it is masked.
- **Temporary passwords with forced change:**
  - When access is restored, the system generates a random 12-character temporary password and texts it to the user. No person types or sees it, and it is never returned by the API or written to logs.
  - It expires after 24 hours (configurable).
  - The user must change it at their next sign-in. The seeded admin must also change their password at the first sign-in.
- **No admin access to passwords.** There is no API route for an admin to see or set a customer's or staff member's password. An admin can change a username, but passwords are only reset by a system-generated temporary password.
- **Throttles** on registration, account lookups, loan quotes and password actions.

## Business rules

- **Phone numbers:**
  - Customer, staff and guarantor phones must be Gambian mobile numbers: 9 digits starting with 83 (QCell), 86 (Comium) or 87 (Africell). This follows PURA's September 2026 numbering migration.
  - Gamcel numbers are not supported yet.
  - Branch and employer phone numbers may be landlines: digits and spaces, with an optional leading `+`.
- **Currency:** all amounts are in Gambian dalasi (GMD), with two decimal places.
- **Daily transfer limit:** this is per source account, set on its account type. It counts completed transfers out of the account on any channel, and resets at midnight Africa/Banjul time.
- **Loans:**
  - The amount must be between GMD 1,000 and GMD 5,000,000.
  - The term is 1 to 12 months at most.
  - There are two repayment plans: *Monthly* (equal monthly instalments) or *Single* (one repayment at the end of the term).
  - A customer can have only one open loan.
  - If the monthly repayment is more than 33% of the declared monthly income, staff see a warning, but the loan is not rejected automatically.
- **Early loan payoff.** A customer can pay off the whole loan at any time:
  - *Monthly plan:* overdue instalments and the current month's instalment are paid in full (with their interest). All later instalments are paid as principal only, so no interest is charged for months not yet reached.
  - *Single plan:* the principal plus interest for the months started since the loan was paid out. That is at least 1 month and at most the full term.

  The same rule is written twice: in PHP (to show the customer the amount) and in `sp_repay_loan` (to charge it). A parity test checks that the two always agree.

## Installation

### Requirements

- PHP 8.3 or later, with the `pdo_mysql` extension
- Composer
- MySQL 8
- Node.js 20.19+ or 22.12+ (required by Vite 8), with npm
- Git

### Steps

1. **Clone the repository.**

   ```sh
   git clone <repository-url> Diamond-bank
   cd Diamond-bank
   ```

2. **Install the dependencies.**

   ```sh
   cd backend && composer install
   cd ../frontend && npm install
   ```

3. **Create the backend `.env`.**

   ```sh
   cd ../backend
   cp .env.example .env
   php artisan key:generate
   ```

   Then edit `backend/.env`:
   - `DB_USERNAME` and `DB_PASSWORD`: your MySQL login. The template already has `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306` and `DB_DATABASE=bank_db`.
   - `ADMIN_USERNAME` and `ADMIN_PASSWORD`: the first admin's login. Choose your own values.

4. **Create the frontend `.env`.**

   ```sh
   cd ../frontend
   cp .env.example .env
   ```

   It sets `VITE_API_URL=http://localhost:8000`.

5. **Create the two databases** (as a MySQL user allowed to create databases):

   ```sql
   CREATE DATABASE bank_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE bank_db_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   The MySQL user in `.env` needs full rights on both databases, including creating stored procedures and functions.

6. **Create the tables and stored procedures, then seed.**

   ```sh
   cd ../backend
   php artisan migrate
   php artisan db:seed
   ```

   `db:seed` runs three seeders:
   - `RoleSeeder` creates the three roles.
   - `TransactionTypeSeeder` creates the transaction types.
   - `AdminSeeder` creates the admin from `ADMIN_USERNAME` and `ADMIN_PASSWORD`. It refuses to run if `APP_ENV=production`.

7. **Start the backend and the frontend** in two terminals:

   ```sh
   # terminal 1
   cd backend
   php artisan serve        # http://localhost:8000

   # terminal 2
   cd frontend
   npm run dev              # http://localhost:5173
   ```

   Open **http://localhost:5173**. Always use `localhost`, not `127.0.0.1`: the session cookies are set for `localhost`.

8. **Sign in as the admin** with `ADMIN_USERNAME` and `ADMIN_PASSWORD`. You must change the password before anything else.

9. **Set up the bank.** In the admin area, fill in *Bank settings*, then add branches, account types, card types and loan types. After that:
   - customers can register on the *Open an account* page;
   - staff can register on the staff sign-up page, and then wait for an admin to approve them.

## Configuration

These are the project's own keys in `backend/.env`. Each one has a default in the code, and `.env.example` lists them with those defaults.

| Key | What it does | Default |
|---|---|---|
| `ADMIN_USERNAME` | Username of the admin created by `AdminSeeder`. | *(none; you must set it)* |
| `ADMIN_PASSWORD` | First password of that admin. It must be changed at the first sign-in. | *(none; you must set it)* |
| `IDLE_TIMEOUT_MINUTES` | Minutes without user activity before the server logs a session out. | `5` |
| `TEMP_PASSWORD_EXPIRY_MINUTES` | How long a texted temporary password works. | `1440` (24 hours) |
| `LOCKOUT_FIRST_MINUTES` | Lock after 5 wrong passwords. | `1` |
| `LOCKOUT_SECOND_MINUTES` | Lock after the next wrong password (and an admin's repeated locks). | `2` |
| `LOCKOUT_RESET_HOURS` | Hours after the last wrong password before the count is forgotten. | `24` |
| `BANK_CARD_BIN` | First 6 digits of generated card numbers. 990000 never matches a real card network. | `990000` |
| `BANK_CARD_VALIDITY_YEARS` | Years a card is valid; it expires at the end of that month. | `3` |
| `BANK_LOAN_MIN_AMOUNT` | Smallest loan, in GMD. | `1000.00` |
| `BANK_LOAN_MAX_AMOUNT` | Largest loan, in GMD. | `5000000.00` |
| `BANK_LOAN_MIN_TERM_MONTHS` | Shortest loan term. | `1` |
| `BANK_LOAN_MAX_TERM_MONTHS` | Longest loan term. | `12` |
| `BANK_LOAN_AFFORDABILITY_WARNING_PERCENT` | Monthly repayment, as a percentage of monthly income, above which staff see a warning. | `33` |
| `BANK_LOAN_STATEMENT_MONTHS` | Months of the customer's transactions shown to staff reviewing a loan. | `6` |
| `SANCTUM_STATEFUL_DOMAINS` | Frontend address that receives session cookies. | Laravel's list of localhost addresses; `.env.example` sets `localhost:5173` |
| `FRONTEND_URL` | Frontend address allowed by CORS. | `http://localhost:5173` |

The number of wrong passwords before the first lock (5) is fixed in `config/bank.php`; it is not an `.env` key.

In the frontend, `frontend/.env` has one key: `VITE_API_URL`, the backend address (default `http://localhost:8000`).

## Running the tests

### Backend

```sh
cd backend
php artisan test
```

The tests run against **`bank_db_testing` only**: `phpunit.xml` sets `DB_DATABASE=bank_db_testing`, and the tests prepare that database themselves (migrating it, then emptying and re-seeding it before each test). They never touch `bank_db`. Create `bank_db_testing` first (installation step 5).

Current result: **612 tests, 13,957 assertions, all passing.** They cover authentication, every role's features, the stored procedures, concurrency (row locking), security (lockouts, branch scoping, idle logout, throttles) and the migrations.

### Frontend

```sh
cd frontend
npm test         # unit tests (Node's built-in test runner)
npm run lint     # oxlint
npm run build    # production build
```

Current result: **14 tests, all passing.** They cover money formatting, phone validation, form errors and the cross-tab idle logout. Lint and build also pass.

## Demo notes

- **Demo SMS outbox.** The app has no real SMS gateway. Every text the bank "sends" (for example, a temporary password after access is restored) is saved in the `demo_sms_outbox` table. You can read it on the *Demo SMS outbox* page at `/demo/sms-outbox`, which the sign-in page links to. The page and its API only exist when `APP_ENV=local`, and show the last 24 hours of messages. Used, replaced and expired codes are masked.
- **Daily transfer limits.** The defaults are GMD 100,000 a day for *Savings* accounts and GMD 500,000 for *Current* accounts; any other account type starts at GMD 100,000. The admin can change each type's limit on the **Account types** page.

## Future work

- Virtual cards.
- Card payments, and enforcing card daily limits.
- Automatic deduction of loan repayments on their due date.
- Online registration with a one-time code (OTP) for people who are already customers at a branch.
- Real SMS delivery through an SMS gateway.
- Support for Gamcel numbers.
- Online deployment.
