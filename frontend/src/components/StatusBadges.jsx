import Badge from './Badge.jsx'

const KYC = { PENDING: ['warning', 'KYC pending'], VERIFIED: ['success', 'Verified'], REJECTED: ['danger', 'KYC rejected'] }
const ACCOUNT = { ACTIVE: ['success', 'Active'], FROZEN: ['info', 'Frozen'], CLOSED: ['neutral', 'Closed'] }
const LOGIN = { ACTIVE: ['success', 'Active'], BLOCKED: ['danger', 'Blocked'], PENDING: ['warning', 'Pending'] }

export function KycBadge({ status }) {
  const [tone, label] = KYC[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}

export function AccountStatusBadge({ status }) {
  const [tone, label] = ACCOUNT[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}

export function LoginStatusBadge({ status }) {
  const [tone, label] = LOGIN[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}

const CARD = {
  REQUESTED: ['warning', 'Requested'],
  ACTIVE: ['success', 'Active'],
  BLOCKED: ['danger', 'Blocked'],
  REJECTED: ['neutral', 'Rejected'],
  EXPIRED: ['neutral', 'Expired'],
}

export function CardStatusBadge({ status }) {
  const [tone, label] = CARD[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}

const LOAN = {
  PENDING: ['warning', 'Pending'],
  AWAITING_ADMIN: ['info', 'Awaiting admin'],
  ACTIVE: ['success', 'Active'],
  REJECTED: ['danger', 'Rejected'],
  CANCELLED: ['neutral', 'Cancelled'],
  CLOSED: ['neutral', 'Closed'],
}

export function LoanStatusBadge({ status }) {
  const [tone, label] = LOAN[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}

// display_status from the API: OVERDUE, DUE and UPCOMING are derived from the due date, never stored.
const INSTALMENT = {
  PAID: ['success', 'Paid'],
  SETTLED: ['neutral', 'Settled'],
  OVERDUE: ['danger', 'Overdue'],
  DUE: ['warning', 'Due'],
  UPCOMING: ['info', 'Upcoming'],
}

export function InstalmentStatusBadge({ status }) {
  const [tone, label] = INSTALMENT[status] ?? ['neutral', status]
  return <Badge tone={tone}>{label}</Badge>
}
