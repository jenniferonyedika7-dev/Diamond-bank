import Alert from '../components/Alert.jsx'

/**
 * Banking actions need verified KYC and at least one account. Returns the
 * message to show instead of them, or null when the customer can bank.
 */
export default function KycNotice({ customer, accounts }) {
  if (customer.kyc_status === 'PENDING') {
    return <Alert tone="info">Your identity is being verified. Visit {customer.branch_name} with your national ID.</Alert>
  }
  if (customer.kyc_status === 'REJECTED') {
    return <Alert>Your verification was not approved. Please contact the bank.</Alert>
  }
  if (accounts.length === 0) {
    return <Alert tone="info">Visit a branch to open your first account.</Alert>
  }
  return null
}
