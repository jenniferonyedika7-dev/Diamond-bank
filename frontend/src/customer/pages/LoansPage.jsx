import { useState } from 'react'
import { Link, useLocation } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { LoanStatusBadge } from '../../components/StatusBadges.jsx'
import { api } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { OPEN_STATUSES, PLAN_LABELS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { useApiList } from '../../lib/useApiList.js'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

const LINK_BUTTON =
  'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2'

export default function LoansPage() {
  const { overview } = useCustomerData()
  const { customer, accounts } = overview
  const location = useLocation()
  const loans = useApiList('/api/v1/customer/loans')
  const [cancelling, setCancelling] = useState(null)
  const [flash, setFlash] = useState(location.state?.flash ?? '')

  const hasOpenLoan = loans.items.some((loan) => OPEN_STATUSES.includes(loan.status))
  const canApply = canBank(customer, accounts) && !loans.loading && !loans.error && !hasOpenLoan
  const applyButton = canApply ? (
    <Link to="/customer/loans/apply" className={`${LINK_BUTTON} bg-navy-900 text-white hover:bg-navy-800 focus-visible:outline-accent-500`}>
      Apply for a loan
    </Link>
  ) : null

  return (
    <section className="space-y-6">
      <PageHeader title="Loans" description="Apply for a loan and follow it from application to the last repayment." action={applyButton} />
      <KycNotice customer={customer} accounts={accounts} />
      <Alert tone="success">{flash}</Alert>
      {hasOpenLoan && (
        <Alert tone="info">
          You can have one loan at a time. You can apply again once your current loan is closed, rejected or cancelled.
        </Alert>
      )}

      {loans.loading ? (
        <Loading />
      ) : loans.error ? (
        <Alert>{loans.error}</Alert>
      ) : loans.items.length === 0 ? (
        <EmptyState title="You have no loans yet." />
      ) : (
        <ul className="space-y-3">
          {loans.items.map((loan) => (
            <LoanCard key={loan.loan_id} loan={loan} onCancel={() => setCancelling(loan)} />
          ))}
        </ul>
      )}

      {cancelling && (
        <ConfirmDialog
          title="Cancel this application?"
          confirmLabel="Cancel application"
          busyLabel="Cancelling…"
          onClose={() => setCancelling(null)}
          onConfirm={async () => {
            const { data } = await api.post(`/api/v1/customer/loans/${cancelling.loan_id}/cancel`)
            setCancelling(null)
            setFlash(data.message)
            loans.reload()
          }}
        >
          <p>
            Your application for <strong>{formatMoney(cancelling.amount)}</strong> stops here and the bank won&apos;t review it further. You
            can apply again afterwards.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function LoanCard({ loan, onCancel }) {
  return (
    <li className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
            {loan.loan_type.type_name} loan
            <LoanStatusBadge status={loan.status} />
          </p>
          <p className="mt-1 text-sm text-slate-700">
            <span className="font-medium tabular-nums">{formatMoney(loan.amount)}</span> · {PLAN_LABELS[loan.repayment_plan]} ·{' '}
            {loan.term_months} {Number(loan.term_months) === 1 ? 'month' : 'months'}
          </p>
          <p className="mt-1 text-xs text-slate-500">Applied {formatDate(loan.application_date)}</p>
          {loan.status === 'REJECTED' && loan.rejection_reason && (
            <p className="mt-2 text-sm text-slate-700">
              <span className="text-slate-500">Reason: </span>
              {loan.rejection_reason}
            </p>
          )}
        </div>
        <div className="flex shrink-0 gap-2">
          <Link
            to={`/customer/loans/${loan.loan_id}`}
            className={`${LINK_BUTTON} bg-white text-navy-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-navy-600`}
          >
            View<span className="sr-only"> {loan.loan_type.type_name} loan of {formatMoney(loan.amount)}</span>
          </Link>
          {loan.can_cancel && (
            <Button variant="secondary" onClick={onCancel}>
              Cancel<span className="sr-only"> application for {formatMoney(loan.amount)}</span>
            </Button>
          )}
        </div>
      </div>
    </li>
  )
}
