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
