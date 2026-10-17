/** Labels for account access requests (customers) and staff access restores. */
export const CHANNEL_LABELS = {
  BRANCH_VISIT: 'Branch visit',
  PHONE_CALL: 'Phone call',
  IN_PERSON: 'In person',
}

export const TYPE_LABELS = {
  UNBLOCK_ONLY: 'Unblock only',
  UNBLOCK_AND_RESET: 'Unblock and send a temporary password',
}

export const RECOVERY_LABELS = {
  LOCKOUT_BLOCKED: 'Locked after too many wrong passwords',
  TEMP_PASSWORD_EXPIRED: 'Temporary password expired unused',
}

export const STATUS_TONES = { PENDING: 'warning', APPROVED: 'success', REJECTED: 'danger' }
