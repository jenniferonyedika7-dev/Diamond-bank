# Diamond Bank: Staff and Administrator Guide

This guide is for the people who run Diamond Bank:

- Part A is for branch staff: tellers and officers who look after customers, accounts, cards and
  loans at a branch.
- Part B is for administrators: they set up the bank, approve staff, unlock logins and give the
  final approval on loans.

Words in **bold** are exactly what you see on the screen: page titles, menu items, buttons and messages.

Several rules are set by the bank and can change: daily transfer limits, the smallest and largest
loan, the loan affordability warning level, how long a temporary password lasts, how long an idle
session stays open, and how many wrong passwords are allowed. The screens always show the current
figures, so this guide does not quote fixed numbers.

## Contents

Part A: Branch staff

- [A1. Registering and waiting for approval](#a1-registering-and-waiting-for-approval)
- [A2. Finding your way](#a2-finding-your-way)
- [A3. Dashboard and search](#a3-dashboard-and-search)
- [A4. Customers](#a4-customers)
- [A5. Opening an account](#a5-opening-an-account)
- [A6. Deposits, withdrawals and freezing an account](#a6-deposits-withdrawals-and-freezing-an-account)
- [A7. Cards](#a7-cards)
- [A8. Loans](#a8-loans)
- [A9. Access requests for locked-out customers](#a9-access-requests-for-locked-out-customers)
- [A10. Audit log](#a10-audit-log)

Part B: Administrators

- [B1. First sign-in and the forced password change](#b1-first-sign-in-and-the-forced-password-change)
- [B2. Finding your way](#b2-finding-your-way)
- [B3. Overview](#b3-overview)
- [B4. Bank setup](#b4-bank-setup)
- [B5. People: Staff](#b5-people-staff)
- [B6. People: Customers](#b6-people-customers)
- [B7. People: Access requests](#b7-people-access-requests)
- [B8. Loans: final approval and payout](#b8-loans-final-approval-and-payout)
- [B9. Audit log](#b9-audit-log)
- [B10. The demo SMS outbox (local demo only)](#b10-the-demo-sms-outbox-local-demo-only)

Both

- [Signing out, idle logout and wrong passwords](#signing-out-idle-logout-and-wrong-passwords)
- [Troubleshooting](#troubleshooting)

---

# Part A: Branch staff

## A1. Registering and waiting for approval

1. On the **Sign in** page, click **Register as staff** (under "Bank employee?").
2. The **Staff registration** form appears: "For Diamond Bank employees. An administrator must approve
   your account before you can sign in."
3. Under **Employee details**, enter your **Full name**, **National ID number**, **Position** (for
   example, Teller), **Phone number** (9 digits starting with 83, 86 or 87) and **Work email**.
4. Under **Sign-in details**, choose a **Username** and a **Password** (at least 8 characters, at least one
   letter and at least one number) and enter it again in **Confirm password**.
5. Submit the form.

You see **Registration received** and **Your account is awaiting admin approval.** Until an administrator
approves you, signing in shows **Your account is awaiting approval.**

When an administrator approves you, they assign you to a branch (and optionally a department).
You can then sign in. Everything you do as staff happens at that branch.

---

## A2. Finding your way

The dark blue bar at the top of every page has these items:

| Menu item | What it is for |
|---|---|
| **Dashboard** | Quick search and KYC counts |
| **Customers** | Find customers, check KYC, edit details, block logins, open accounts |
| **Cards** | Issue or reject card requests, unblock cards |
| **Loans** | Record loan checks, approve or reject applications, record cash payments |
| **Audit log** | A read-only record of changes at your branch |

On the right there is a **Search customers** box. It searches every customer by name, ID, phone, email or
username. Next to it is your name with **Staff** underneath; click it for **Change password** and **Log
out**. Under the bar, a thin strip shows your branch name, branch code and department.

On a small screen, all of this is under the **Menu** button.

### Branch rules

You work at your current branch. The rules are:

| You can do this for any customer or account | Only for customers and accounts at your branch |
|---|---|
| Find and view customers and their accounts | Verify or reject KYC |
| Cash deposits and withdrawals | Edit customer details |
| Open an account for a verified customer (it is opened at your branch) | Block or unblock a customer's login |
| | Raise an access request |
| | Freeze or unfreeze an account |
| | Issue, reject and unblock cards (the **Cards** page only lists cards on your branch's accounts) |
| | Review loans and record cash loan payments (the **Loans** page only lists your branch's loans) |

If you try something that belongs to another branch, you see **This customer is managed at another
branch.**, **This account is managed at another branch.** or **This loan is managed at another branch.**

---

## A3. Dashboard and search

Open **Dashboard**. You see **Welcome, (your name)** and:

- **Find an account or customer**: type an account number (for example DB0010000001) and click **Search**
  to go straight to that account. Anything else (a name, ID, phone, email or username) searches the
  customers.
- If customers are waiting for their identity check, a yellow banner says **(number) customers awaiting
  KYC**. Click **Review →** to see them.
- Three boxes: **Awaiting KYC**, **Verified** and **Rejected**. Click a box to open that list.

---

## A4. Customers

Open **Customers** ("Check identity documents, keep details up to date and open accounts.").

### Finding a customer

1. Choose a tab: **Pending KYC** (the default), **Verified**, **Rejected** or **All**.
2. Type in **Search by name, national ID, phone, email or username**. The list updates as you type.
3. Click a customer to open their page.

Each row shows the name, username, KYC badge (**KYC pending**, **Verified** or **KYC rejected**), and
**Login blocked** if their login is blocked.

The customer page has these sections: **Profile**, **KYC**, **Online login**, **Accounts**, **Cards** and
**Recent transactions**.

### Verifying identity (KYC)

A new customer's identity must be checked before any account can be opened. Do this with the customer's
national ID in front of you.

To verify:

1. On the customer page, under **KYC**, click **Verify**.
2. The **Verify customer?** box asks you to confirm you have checked their identity document.
3. Click **Verify**. You see **Customer verified.**

To reject:

1. Under **KYC**, click **Reject**.
2. In **Reject KYC: (name)**, type a **Reason**. It is recorded in the audit log.
3. Click **Reject KYC**. You see **KYC rejected.** No accounts can be opened for a customer whose KYC is
   rejected.

You can only verify or reject a customer whose KYC is still pending.

### Editing customer details

Customers can't change their own details online, so they come to you.

1. Under **Profile**, click **Edit**.
2. In **Edit customer details**, change the **First name**, **Last name**, **Date of birth**, **Gender**,
   **National ID number**, **Phone number**, **Email address** or **Home branch**.
   - The national ID is locked once KYC has been decided: "Locked: the national ID can only be changed
     while KYC is pending."
3. Click **Save changes**. You see **Customer details updated.**

### Blocking and unblocking a login

Blocking stops the customer signing in online. Their accounts are not affected.

To block:

1. Under **Online login**, click **Block login**.
2. In **Block login: (name)**, type a **Reason** and click **Block login**. The customer is signed out
   straight away. You see **Customer login blocked.**

To unblock:

1. Under **Online login**, click **Unblock login**.
2. In **Unblock login?**, click **Unblock**. You see **Customer login unblocked.**

If the login was blocked because of too many wrong passwords, you can't unblock it directly. The
**Online login** section shows **Locked after too many wrong passwords**, and clicking **Unblock login**
opens **Access request needed**. Follow [A9](#a9-access-requests-for-locked-out-customers) instead.

---

## A5. Opening an account

You can open an account for any customer whose KYC is **Verified**. The account is opened at your branch,
in GMD.

1. Open the customer's page.
2. In the **Accounts** section, click **Open account**. (The button is disabled until KYC is verified:
   "Accounts can be opened once the customer's KYC is verified.")
3. Choose the **Account type**. Each type shows its minimum balance.
4. Enter the **Initial deposit (GMD)**. It must be at least the account type's minimum balance.
5. Click **Open account**.

You go straight to the new account's page with the message **Account (number) opened.**

If you see **No account types are set up yet. Ask an administrator to add one.**, an administrator must
add an account type first.

---

## A6. Deposits, withdrawals and freezing an account

To reach an account's page: open **Customers**, click the customer, then click the account in the
**Accounts** section. (You can also type the account number in the Dashboard search.)

The account page shows the account number and status, its type, interest rate, minimum balance, branch
and opening date, the **Available balance**, and the account's **Transaction history**.

### Deposit cash

1. Click **Deposit**.
2. In **Deposit cash: (account number)**, enter the **Amount (GMD)** and an optional **Description**.
3. Click **Deposit**.
4. You see **Deposit successful.** and the **New balance**. Click **Done**.

### Withdraw cash

1. Click **Withdraw**.
2. In **Withdraw cash: (account number)**, check the current balance and the minimum. Enter the **Amount
   (GMD)** and an optional **Description**.
3. Click **Withdraw**.
4. You see **Withdrawal successful.** and the **New balance**. Click **Done**.

A withdrawal can't take the account below its minimum balance: **Insufficient funds: this withdrawal would
take the account below its minimum balance.**

### Freeze and unfreeze

A frozen account allows no deposits, withdrawals or transfers. You can only freeze accounts at your
branch.

1. Click **Freeze**.
2. In **Freeze (account number)**, type a **Reason** and click **Freeze account**. You see **Account
   frozen.**

To undo it, click **Unfreeze**, then **Unfreeze** in the **Unfreeze account?** box. You see **Account
unfrozen.**

**Deposit** and **Withdraw** are disabled while an account is frozen. Closed accounts can't be changed.

---

## A7. Cards

Open **Cards** ("Issue or reject debit card requests, and unblock cards, for accounts held at your
branch.").

Choose a tab: **Requests** (the default), **Active**, **Blocked** or **Rejected**. You can **Search by
customer name, account number or last 4 digits**. Each row links to the customer and the account.

### Issuing a card

1. On the **Requests** tab, click **Issue** next to the request.
2. The **Issue this card?** box shows the card type, customer and account. The card number is generated
   now; the card is valid until the end of the month, a number of years ahead set by the bank.
3. Click **Issue card**. You see **Card issued: (last digits).**

A card can't be issued if the account isn't active, the customer's login isn't active, or the account
already has an active card.

### Rejecting a request

1. Click **Reject** next to the request.
2. In **Reject card request: (name)**, type a **Reason**. The customer sees this reason on their Cards
   page.
3. Click **Reject request**. You see **Card request rejected.**

### Unblocking a card

Customers can block their own card, but only the branch can unblock it.

1. On the **Blocked** tab, click **Unblock**.
2. In **Unblock this card?**, check with the customer that the card was found.
3. Click **Unblock**. You see **Card unblocked.**

You can't unblock a card that has expired, whose account isn't active, or when a replacement card is
already active on the account.

---

## A8. Loans

Open **Loans** ("Loans for accounts at your branch. Record the four checks, then approve or reject.
Record cash payments on active loans.").

### The loan list

Choose a tab: **Pending** (the default), **Awaiting admin**, **Active**, **Rejected**, **Cancelled** or
**Closed**. Each tab shows how many loans it holds. You can **Search by customer name, account number or
loan number**.

Each row shows the customer, amount, loan type, repayment plan and term, the **Affordability** figure,
and a yellow **Above (level)%** badge when the repayment is high compared with the customer's income.
Click **Review** to open the loan.

### What the loan page shows

- **Loan**: the terms, the **Account to credit**, the **Purpose** and the description
- **Affordability**: what share of the declared monthly income goes on the monthly repayment
- **Applicant**: monthly income, TIN, employment details, and phone and email from the profile
- **Guarantor**: name, occupation, phone, email and address
- **Verification checks**: the four checks
- **Decisions**: who approved or rejected the loan, and when
- **Repayment schedule** and **Payments** (once the loan is active)
- **Statement**: the customer's transactions on all their accounts over a recent period set by the bank

### The affordability warning

The bank sets a warning level. If the monthly repayment is above that share of the declared income, the
**Affordability** panel says: **Above the …% warning level. This is a warning, not a rule: weigh it with
the statement and the checks before deciding.** It never rejects a loan by itself. Otherwise it says
**Within the …% warning level.**

### The four checks

Before you can approve, you must record all four checks:

1. **Bank statement reviewed**
2. **Customer employment verified**
3. **Guarantor contacted and aware of the loan**
4. **Guarantor occupation verified**

To record a check:

1. Under **Verification checks**, click **Record** next to the check.
2. Type a **Note** saying what you checked and what you found.
3. Click **Record check**. You see, for example, **Bank statement reviewed: recorded.**

To correct a check, click **Update**, change the note and click **Update check**. The previous note stays
in the audit log. Checks can only be recorded while the application is **Pending**.

### Approving or rejecting

The **Decision** panel appears while the loan is **Pending**. Until all checks are done it says **Record
these checks before approving: …** and **Approve** is disabled.

To approve:

1. Click **Approve**.
2. **Approve this application?** explains that the loan goes to an administrator for the final approval
   and that no money moves yet.
3. Click **Approve**. You see **Loan approved. It now needs an administrator's approval.** The loan moves
   to **Awaiting admin**.

To reject:

1. Click **Reject**.
2. In **Reject loan #(number): (name)**, type a **Reason**. The customer sees it on their Loans page.
3. Click **Reject application**. You see **Loan application rejected.**

### Recording cash payments

When a customer repays a loan in cash at your branch:

1. Open the loan (it must be **Active**). The **Record a cash payment** panel shows the **Outstanding
   balance**, the **Next payment** and, for monthly loans, the **Instalments left**.
2. Click **Record next instalment (amount)** or **Record payment of everything (amount)**. A loan with a
   single payment at the end can only be paid in full.
3. **Record this cash payment?** shows what the payment covers (instalment, principal, interest, or the
   interest charged and waived when paying everything). It reminds you that no account is debited and the
   payment is recorded at your branch under your name.
4. Take the cash, then click **Record (amount)**.

You see **Cash payment of … recorded.** (and **The loan is now paid off.** if it was the last payment),
with a **Cash payment recorded** receipt showing the receipt number, amounts, **Loan balance after** and
**Received at**.

If you see **The amount has changed. Please review it again.**, the amount due changed (for example, an
instalment just became overdue). The new amount is shown; check it with the customer and record again.

---

## A9. Access requests for locked-out customers

A customer whose login was blocked after too many wrong passwords, or whose temporary password expired
unused, can't be unblocked directly. You check who they are, then an administrator decides.

The **Online login** section on the customer page shows **Locked after too many wrong passwords** or
**Temporary password expired unused**.

1. Make sure the customer is in front of you or on the phone.
2. On the customer page, under **Online login**, click **Raise access request**.
3. Fill in **Account access request: (name)**:
   - **How did the customer contact us?**: **Branch visit** or **Phone call**
   - **Identity checks (all required)**: tick **Date of birth confirmed**, **Registered phone number
     confirmed** and **Account and transaction history reviewed**
   - **What does the customer need?**:
     - **Unblock only**: the customer remembers their password
     - **Unblock and send a temporary password**: the system texts a temporary password to the
       registered phone. Nobody at the bank sees it.
     If the temporary password has expired, only the second option is offered.
   - **Notes**: what the customer told you and how you checked their identity
   - Tick **I confirm this customer contacted our branch and I verified their identity.**
4. Click **Send for approval**.

You see **Access request sent to the administrator for approval.** The customer gets an SMS saying their
request was received, and another when it is decided. While it waits, the customer page shows **Access
request pending since … Waiting for an administrator.** A customer can only have one pending request at a
time.

---

## A10. Audit log

Open **Audit log** ("Every change made through the system, newest first. Read-only.").

As staff you see the entries for your branch: actions by staff working at your branch on that day, and
actions by customers whose home branch it is.

Filter by **Action**, **Table** (the kind of record changed), **Username**, **From** and **To**. Each row
shows **When**, **Action**, **Record** and **By**. Click **Details** to see exactly what changed, and
**Hide details** to close it.

---

# Part B: Administrators

## B1. First sign-in and the forced password change

The first administrator login is created when the system is installed. The person installing it chooses
the username and starting password.

1. Sign in on the **Sign in** page with that username and password.
2. You are taken straight to **Change your password**: **You must change your password before
   continuing.** While a password change is required, the top bar only offers **Log out**.
3. Enter the **Current password** (the starting password), then a **New password** and **Confirm new
   password**. It must have at least 8 characters, at least one letter and at least one number, and be
   different from the current one.
4. Click **Change password**. You land on the admin **Overview**.

The same forced change happens to any staff member or customer who signs in with a temporary password.

---

## B2. Finding your way

The top bar for administrators has:

| Menu item | Contains |
|---|---|
| **Overview** | The bank at a glance |
| **Bank setup** | **Bank settings**, **Branches**, **Account types**, **Card types**, **Loan types**, **Departments** |
| **People** | **Customers**, **Staff**, **Access requests** |
| **Loans** | Final approval and payout |
| **Audit log** | Every change in the system |

**Bank setup** and **People** are drop-down menus: click them to see their items. The **Search customers**
box opens **People** > **Customers** with your search filled in. Your name, with **Administrator**
underneath, opens **Change password** and **Log out**.

---

## B3. Overview

Open **Overview** ("The state of the bank at a glance.").

Banners at the top appear when something needs you:

- **Set up your bank first**, with **Go to Bank settings**, when the bank details are missing. Branches,
  and then customers and staff, depend on them.
- **(number) access requests awaiting your decision**. Click **Review →**.
- **(number) staff members awaiting approval**. Click **Review →**.

Below them are the overview boxes. Most of them are clickable:

| Box | Shows | Opens |
|---|---|---|
| **Pending staff** | Staff registrations waiting for approval | **Staff**, **Pending** tab |
| **Active staff** | Active staff, with the number blocked | **Staff**, **Active** tab |
| **Access requests** | Pending access requests | **Access requests** |
| **Branches** | Number of branches | **Branches** |
| **Customers** | All customers, with KYC pending / verified / rejected | **Customers** |
| **Accounts** | Number of accounts | (not clickable) |
| **Bank details** | The bank's name, or **Missing** | **Bank settings** |

---

## B4. Bank setup

Set things up in this order: bank details, then branches, then the types. Customers can't register until
there is a branch, staff can't be approved without one, and customers can't get accounts, cards or loans
until those types exist.

The **Branches**, **Account types**, **Card types**, **Loan types** and **Departments** pages all work the
same way:

- Click the **Add …** button at the top (for example **Add branch**), fill in the form, then click **Add
  …** again to save.
- Click **Edit** on a row, change it and click **Save changes**.
- Click **Delete** on a row, then confirm. This can't be undone. Anything still in use can't be deleted:
  you see, for example, **This branch can't be deleted: it is used by … .**

### Bank settings

Open **Bank setup** > **Bank settings**. The first time, the page is called **Set up your bank**;
afterwards it is **Bank details** ("The bank this system runs. There is only ever one.").

1. Enter the **Bank name**, the **SWIFT code** (8 or 11 letters or digits, for example DBNKGMGM) and the
   date it was **Established on**.
2. Click **Save bank details** (first time) or **Save changes**.

You see **Bank details saved.** or **Bank details updated.**

### Branches

Open **Bank setup** > **Branches** ("Every customer, account and staff member belongs to a branch.").

- Fields: **Branch name**, **Branch code** (letters, digits and hyphens, saved in capitals, for example
  BJL001), **Address**, **Phone number** and **Opened on**.
- The list shows each branch's customers and staff, and you can **Search by name or code**.
- If the bank details are missing, adding a branch shows **Set up the bank details first.** with a link
  **Go to Bank settings**.

### Account types and the daily transfer limit

Open **Bank setup** > **Account types** ("The kinds of account customers can open, with their interest
rate, minimum balance and daily transfer limit.").

- **Name**: for example, SAVINGS.
- **Interest rate (% per year)**: 0 to 100, up to 2 decimal places.
- **Minimum balance (GMD)**: every account of this type must keep at least this much. It is also the
  smallest initial deposit.
- **Daily transfer limit (GMD)**: the most each account of this type can transfer out per day
  (00:00–23:59, Banjul time).

The daily transfer limit is set here, per account type, not on Bank settings. A change applies at once
to every account of that type. Customers see what is left of today's limit on their **Transfer** page.

### Card types

Open **Bank setup** > **Card types** ("The kinds of debit card customers can request, with their daily
spending limit.").

- **Name**: debit cards only, for example Debit Classic. Names are saved in Title Case.
- **Daily limit (GMD)**: more than 0, up to 2 decimal places.

### Loan types

Open **Bank setup** > **Loan types** ("The kinds of loan customers can apply for, with their annual
interest rate.").

- **Name**: for example Personal or Business. Names are saved in Title Case.
- **Annual interest rate (%)**: more than 0 and below 100. A new rate applies to new applications only.

Until there is at least one loan type, customers see **Loans are not available yet.**

### Departments

Open **Bank setup** > **Departments** ("Staff can be assigned to a department when they are approved.").
The only field is the **Department name**.

---

## B5. People: Staff

Open **People** > **Staff** ("Approve new staff registrations, block or unblock staff accounts, restore
access after a lockout, and change usernames.").

Choose a tab: **Pending**, **Active** or **Blocked**, and **Search by name or username**. Each card
shows the staff member's position, national ID, email, phone, branch, department and registration date.

### Approving a registration

1. On the **Pending** tab, click **Approve**.
2. In **Approve (name)**, choose the **Branch** and, if you like, a **Department (optional)**.
3. Click **Approve**. You see **(username) has been approved.** They can sign in as staff from today.

If there are no branches yet, the box says **Add a branch first: staff must be assigned to a branch when
they are approved.** with a link **Go to Branches**.

### Rejecting a registration

1. On the **Pending** tab, click **Reject**.
2. In **Reject registration: (name)**, type a **Reason** and click **Reject registration**.

They can't sign in, and this can't be undone: they would need to register again. Rejected registrations
appear on the **Blocked** tab with a **Rejected** badge.

### Blocking and unblocking

1. On the **Active** tab, click **Block**. In **Block staff member: (name)**, type a **Reason** and click
   **Block**. They are signed out straight away.
2. To undo it, on the **Blocked** tab click **Unblock**, then **Unblock** in **Unblock staff member?**.

### Restoring access after a lockout

A staff member blocked after too many wrong passwords (badge **Locked after too many wrong passwords**), or
whose temporary password expired (**Temporary password expired unused**), can't be unblocked with the
normal **Unblock** button. Use **Restore access**:

1. Click **Restore access**.
2. In **Restore access: (name)**, choose **How did they contact you?**: **In person** or **Phone call**.
3. Write a **Verification note**: how you confirmed who they are. It is recorded in the audit log.
4. Choose **What do they need?**:
   - **Unblock only**: they remember their password
   - **Unblock and send a temporary password**: texted to their phone
   If the temporary password has expired, only the second option is offered.
5. Click **Continue**, check the summary, then click **Restore access**.

With a temporary password, the staff member must choose a new password when they sign in. Nobody sees the
temporary password.

### Changing a username

1. Click **Change username** on any staff card.
2. In **Change username: (name)**, enter the **New username**: 3 to 50 characters, using letters, numbers,
   dots, dashes and underscores.
3. Click **Change username**. You see **Username changed from … to ….**

They stay signed in and use the new username next time. Their password is not affected. Let them know about
the change.

---

## B6. People: Customers

Open **People** > **Customers** ("All customers and their accounts, read-only. Branch staff handle KYC,
accounts and blocks.").

- Search by name, national ID, phone, email or username.
- Filter by **KYC status** and **Home branch**.
- Click a customer to see their **Profile**, **Online login** and **Accounts** at every branch.

As an administrator you can't change customers' details, KYC, accounts or logins. The one exception is the
customer's username: under **Online login**, click **Change username** (it works the same way as for staff).

---

## B7. People: Access requests

Open **People** > **Access requests** ("Customers locked out of their online login. Staff at the home
branch checked their identity; you approve or reject.").

Choose a tab: **Pending**, **Approved** or **Rejected**, then click a request to open it.

The request page shows:

- **Request**: what the customer **Needs**, **Why** (for example **Locked after too many wrong
  passwords**), the **Contact** channel, the **Branch**, who **Raised by** and when
- **Staff checks**: the identity checks the staff member ticked, and their declaration
- **Notes** from the staff member
- **Customer**: name, date of birth, national ID, **Registered phone**, email, home branch, KYC status,
  login status and accounts

### Approving

1. Click **Approve**.
2. **Approve access request?** says the customer will be able to sign in again, and either that a temporary
   password will be texted to them, or that they keep their own password and get an SMS saying they are
   unblocked.
3. Click **Approve**. You see **Access request approved. The customer has been sent a temporary password by
   SMS.** or **Access request approved. The customer has been told by SMS that their login is unblocked.**

### Rejecting

1. Click **Reject**.
2. In **Reject access request**, type a **Rejection reason**. The login stays blocked, and the customer
   gets an SMS asking them to visit their branch.
3. Click **Reject request**. You see **Access request rejected. The customer has been told by SMS.**

---

## B8. Loans: final approval and payout

Every loan needs two approvals: first by staff at the customer's branch, then yours. The same person can't
make both approvals.

Open **Loans** ("Loans at every branch. Staff have approved the ones awaiting you; your approval pays the
loan out."). It opens on the **Awaiting admin** tab and also shows each loan's branch.

1. Click **Review** on a loan.
2. Read the loan page: the same panels staff see (see [A8](#what-the-loan-page-shows)), including the
   **Affordability** panel and the **Verification checks**, which are "Recorded by branch staff before
   their approval." You can't change the checks.
3. In the **Decision** panel, choose:
   - **Approve and disburse**: **Approve and pay out this loan?** says the amount will be paid into the
     customer's account now, the repayment schedule starts today, and this can't be undone. Click
     **Approve and disburse**. You see **Loan approved. (amount) was paid into account (number).**
   - **Reject**: type a **Reason** (the customer sees it) and click **Reject application**.

Administrators don't record cash payments; they can see the **Payments** history of each loan.

---

## B9. Audit log

Open **Audit log**. It works as described in [A10](#a10-audit-log), but administrators see every
entry, from all branches.

---

## B10. The demo SMS outbox (local demo only)

When Diamond Bank runs as a local demo on a developer's computer, no real text messages are sent.
Instead they appear in the **Demo SMS outbox**. In a real installation this page does not exist.

To open it, click **Demo SMS outbox** at the bottom of the **Sign in** page (the link is only shown in the
local demo), or go to the address /demo/sms-outbox.

- It shows **Simulated SMS — development only.** and the texts sent in the last 24 hours, newest first,
  with the recipient's name, phone number and time.
- Click **Refresh** to load new messages.
- A temporary password that has been used, replaced or has expired is hidden, with a note saying which.
- Outside the local demo the page shows **Not available**: "The demo SMS outbox only exists in the local
  development environment."

Use it to read the temporary passwords and other texts sent after access requests and restores.

---

# Both

## Signing out, idle logout and wrong passwords

- **Log out**: use your name menu at the top right.
- **Idle logout**: if you do nothing for a while (the bank sets how long), a box **Are you still there?**
  counts down. Click **Stay signed in** to continue. Otherwise you are signed out and see **You were logged
  out after … minutes of inactivity.**
- **Wrong passwords**: wrong passwords on the **Sign in** page and on **Change password** are counted. After
  several in a row you are locked for a short time (**Too many wrong passwords. Try again in …
  minutes.**), then for a longer time. One more wrong password after that blocks a staff login (**Your
  account is locked. Please contact the administrator.**); an administrator then uses **Restore access**.
  Administrators are never blocked this way; they just get another lock.

---

## Troubleshooting

### Signing in

| You see | What it means | What to do |
|---|---|---|
| **Invalid username or password.** | Wrong username or password | Try again carefully; wrong passwords are counted |
| **Your account is awaiting approval.** | Your staff registration hasn't been approved yet | Wait for an administrator |
| **Your account is blocked. Please contact the bank.** | Your login was blocked or your registration rejected | Contact an administrator |
| **Your account is locked. Please contact the administrator.** | Blocked after too many wrong passwords | An administrator must use **Restore access** |
| **Too many wrong passwords. Try again in … minutes.** | Temporary lock | Wait, then use the right password |
| **Too many login attempts. Try again in … seconds.** | Too many attempts from this device | Wait, then try again |
| **This temporary password has expired. Please contact the administrator.** | The temporary password was not used in time | An administrator restores access again with a new temporary password |
| **You must change your password before continuing.** | First sign-in, or a temporary password | Change your password |
| **You are not assigned to a branch. Contact the administrator.** | Your staff login has no current branch | An administrator must assign you to a branch |
| **You were logged out after … minutes of inactivity.** | Idle logout | Sign in again |

### Customers and accounts (staff)

| You see | What it means | What to do |
|---|---|---|
| **This customer is managed at another branch.** | The action belongs to the customer's home branch | Ask that branch, or change the home branch first if the customer has moved |
| **This account is managed at another branch.** | Only the account's branch can freeze or unfreeze it | Ask that branch |
| **Only customers with pending KYC can be verified.** / **… rejected.** | KYC has already been decided | No action needed |
| **This login was locked after too many wrong passwords. Raise an account access request instead.** | Can't unblock directly | See [A9](#a9-access-requests-for-locked-out-customers) |
| **This customer already has a pending access request.** | A request is already waiting | Wait for an administrator |
| **This customer has no online login.** | The customer never registered online | Nothing to unblock |
| **The customer must be KYC verified before an account can be opened.** | KYC not verified | Verify KYC first |
| **The initial deposit is below the minimum balance for this account type.** | Deposit too small | Enter at least the minimum balance |
| **Insufficient funds: this withdrawal would take the account below its minimum balance.** | Not enough money | Withdraw less |
| **This account is frozen.** / **This account is closed.** | No cash can move | Unfreeze first (if it's your branch's account) |
| **Only an active account can be frozen.** / **Only a frozen account can be unfrozen.** | The account is already in that state | Reload the page |
| **Closed accounts can't be changed.** | The account is closed | No action possible |
| **Another transaction on this account was being processed at the same moment. Nothing was changed; please try again.** | Two operations touched the account at once | Try again |

### Cards (staff)

| You see | What it means | What to do |
|---|---|---|
| **The account is not active, so the card can't be issued.** | The account is frozen or closed | Unfreeze it first, or reject the request |
| **The customer's login is not active, so the card can't be issued.** | The customer's login is blocked | Resolve the login first |
| **This account already has an active card.** | One card per account | Reject the request |
| **This card has expired and can't be unblocked.** | Expired card | The customer requests a new card |
| **A replacement card is already active on this account.** | The customer already has a new card | The old card stays blocked |
| **Only a requested card can be issued.** / **Only a blocked card can be unblocked.** | Someone else already dealt with it | Reload the page |

### Loans

| You see | What it means | What to do |
|---|---|---|
| **Record all four checks before approving. Missing: ….** | Checks not done | Record the missing checks |
| **Checks can only be recorded while the application is pending.** | The loan has moved on | No action needed |
| **Only a pending loan application can be approved by staff.** | Already approved, rejected or cancelled | Reload the page |
| **Only a loan awaiting admin approval can be rejected here.** | The loan is not awaiting you | Reload the page |
| **This loan is no longer awaiting approval.** | Someone else decided it first, or the customer cancelled | Reload the page |
| **The same person can't make both approvals.** | You gave the first approval | Another administrator must give the final one |
| **The customer is not KYC verified.** / **The customer's login is not active.** | The customer can't receive the loan right now | Resolve KYC or the login first, or reject |
| **The disbursement account is frozen.** / **… is closed.** | The account chosen for the payout can't receive money | Unfreeze it, or reject the loan |
| **The amount has changed. Please review it again.** | The amount due changed during a cash payment | Check the new amount and record again |
| **This loan is not active.** | The loan isn't being repaid | Check the loan's status |
| **This loan is repaid in one payment. Choose to pay everything now.** | Single-payment loans can't be paid by instalment | Use **Record payment of everything** |

### Administration

| You see | What it means | What to do |
|---|---|---|
| **Set up the bank details first.** | No bank details yet | Fill in **Bank settings** first |
| **This … can't be deleted: it is used by ….** | The branch or type is still in use | Leave it, or edit it instead |
| **Only pending staff can be approved.** / **… rejected.** | The registration has already been decided | Reload the page |
| **This staff member was locked out by wrong passwords. Use Restore access instead.** | Plain unblock isn't allowed after a lockout | Use **Restore access** |
| **This registration was rejected and can't be unblocked.** | Rejected registrations are final | They must register again |
| **The temporary password has expired, so they need a new one: choose unblock and reset.** | **Unblock only** won't help | Choose **Unblock and send a temporary password** |
| **You raised this access request, so another administrator must approve it.** | You can't approve your own request | Ask another administrator |
| **Only a pending access request can be approved.** / **… rejected.** | Already decided | Reload the page |
| **This customer's login no longer needs an access request. Reject the request instead.** | The login was already reopened | Reject the request |
| **The temporary password has expired, so unblocking alone would not let the customer sign in. Reject the request instead.** | The request asks for **Unblock only** but a new password is needed | Reject it; staff raise a new request with a temporary password |
| **This is already the username.** / **The user name has already been taken.** | Username unchanged or in use | Choose a different username |
| **The username may only contain letters, numbers, dots, dashes and underscores.** | Invalid characters | Remove the other characters |
| **You do not have permission to access this resource.** | That page is for another role | Use your own menu |

### Anywhere

| You see | What it means | What to do |
|---|---|---|
| **Can't reach the server. Check your connection and try again.** | The connection dropped | Check your connection and try again |
| **Too many attempts. Try again in … seconds.** | Too many requests in a short time | Wait, then try again |
| **Something went wrong. Please try again.** | An unexpected error | Try again; if it persists, tell the system administrator |
