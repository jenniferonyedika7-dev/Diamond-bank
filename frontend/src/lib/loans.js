/**
 * Labels for loan codes. Display only: every amount, instalment and total
 * comes from the API, never from arithmetic here.
 */

export const PLAN_LABELS = { MONTHLY: 'Monthly instalments', SINGLE: 'Single payment at the end' }

export const PLAN_HELP = {
  MONTHLY: "You repay every month. Each payment covers that month's interest and part of the loan.",
  SINGLE: 'You repay the whole loan and all its interest in one payment at the end of the term.',
}

export const PURPOSE_LABELS = {
  BUSINESS: 'Business',
  EDUCATION: 'Education',
  MEDICAL: 'Medical',
  HOME: 'Home',
  PERSONAL: 'Personal',
  OTHER: 'Other',
}

export const EMPLOYMENT_LABELS = {
  EMPLOYED: 'Employed',
  BUSINESS_OWNER: 'Business owner',
  CONTENT_CREATOR: 'Content creator',
  OTHER: 'Other',
}

export const EMPLOYMENT_FIELD_LABELS = {
  employer_name: 'Employer name',
  workplace_address: 'Workplace address',
  employer_phone: 'Employer phone',
  job_title: 'Job title',
  business_name: 'Business name',
  business_registration_number: 'Business registration number',
  platform: 'Platform',
  account_handle: 'Account handle',
  employment_description: 'Describe your work',
}

/** A customer may have one loan in these statuses (backend Loan::OPEN_STATUSES). */
export const OPEN_STATUSES = ['PENDING', 'AWAITING_ADMIN', 'ACTIVE']

/** Status tabs on the staff and admin loan lists. */
export const STATUS_TABS = [
  { id: 'pending', label: 'Pending', status: 'PENDING', empty: 'No applications are waiting for the checks.' },
  { id: 'awaiting', label: 'Awaiting admin', status: 'AWAITING_ADMIN', empty: 'No loans are waiting for an administrator.' },
  { id: 'active', label: 'Active', status: 'ACTIVE', empty: 'No active loans.' },
  { id: 'rejected', label: 'Rejected', status: 'REJECTED', empty: 'No rejected applications.' },
  { id: 'cancelled', label: 'Cancelled', status: 'CANCELLED', empty: 'No cancelled applications.' },
  { id: 'closed', label: 'Closed', status: 'CLOSED', empty: 'No closed loans.' },
]

// The same formats the backend checks (Tin::PATTERN and the phone rule), so a step can't move on with an obvious typo.
export const TIN_PATTERN = /^\d{8,15}$/
export const PHONE_PATTERN = /^\+?[0-9 ]{7,20}$/
export const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
