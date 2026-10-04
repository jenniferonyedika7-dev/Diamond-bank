import PageHeader from '../../components/PageHeader.jsx'
import AccountCards from '../AccountCards.jsx'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

export default function AccountsPage() {
  const { overview } = useCustomerData()
  const { customer, accounts } = overview

  return (
    <section className="space-y-6">
      <PageHeader title="Accounts" description="Choose an account to see its full history." />
      <KycNotice customer={customer} accounts={accounts} />
      {canBank(customer, accounts) && <AccountCards accounts={accounts} />}
    </section>
  )
}
