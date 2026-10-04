import { Link } from 'react-router'
import PageHeader from '../../components/PageHeader.jsx'
import TransactionList from '../../components/TransactionList.jsx'
import { formatMoney } from '../../lib/money.js'
import AccountCards from '../AccountCards.jsx'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

export default function OverviewPage() {
  const { overview } = useCustomerData()
  const { customer, accounts, totals, recent_transactions: recent } = overview

  return (
    <section className="space-y-6">
      <PageHeader title={`Hello, ${customer.first_name}`} description="Your accounts at Diamond Bank." />

      <KycNotice customer={customer} accounts={accounts} />

      {canBank(customer, accounts) && (
        <>
          <div className="flex flex-col gap-4 rounded-2xl bg-navy-900 p-6 text-white sm:flex-row sm:items-end sm:justify-between">
            <div>
              <p className="text-sm text-navy-100">Total balance</p>
              {totals.map((t) => (
                <p key={t.currency_code} className="mt-1 text-4xl font-semibold tabular-nums tracking-tight">
                  {formatMoney(t.balance)}
                </p>
              ))}
            </div>
            <Link
              to="/customer/transfer"
              className="inline-flex items-center justify-center rounded-lg bg-accent-400 px-4 py-2.5 text-sm font-semibold text-navy-900 hover:bg-accent-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
              Transfer money
            </Link>
          </div>

          <div>
            <h2 className="mb-3 text-lg font-semibold text-navy-900">Your accounts</h2>
            <AccountCards accounts={accounts} />
          </div>

          <div>
            <h2 className="mb-3 text-lg font-semibold text-navy-900">Recent transactions</h2>
            {recent.length === 0 ? (
              <p className="text-sm text-slate-600">No transactions yet.</p>
            ) : (
              <TransactionList transactions={recent} showAccount />
            )}
          </div>
        </>
      )}
    </section>
  )
}
