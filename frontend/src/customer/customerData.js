import { createContext, useContext } from 'react'

export const CustomerDataContext = createContext(null)

/**
 * { status: 'loading'|'ready'|'error', overview, error, reload } from <CustomerShell>.
 * overview = GET /api/v1/customer/overview: { customer, accounts, totals, recent_transactions }.
 */
export function useCustomerData() {
  return useContext(CustomerDataContext)
}

/** Banking actions need verified KYC and at least one account (see KycNotice for the messages). */
export function canBank(customer, accounts) {
  return customer.kyc_status === 'VERIFIED' && accounts.length > 0
}
