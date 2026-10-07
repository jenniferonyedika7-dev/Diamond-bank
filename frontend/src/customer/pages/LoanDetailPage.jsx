import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import Loading from '../../components/Loading.jsx'
import { LoanStatusBadge } from '../../components/StatusBadges.jsx'
import { api, getErrorMessage } from '../../lib/api.js'
import { formatDate, formatDateTime } from '../../lib/format.js'
import { PURPOSE_LABELS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { Field, LoanTermsFields, Panel } from '../../shared/loans/LoanParts.jsx'
import ScheduleTable from '../../shared/loans/ScheduleTable.jsx'

export default function LoanDetailPage() {
  const { loanId } = useParams()
  const [loan, setLoan] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [cancelling, setCancelling] = useState(false)
  const [flash, setFlash] = useState('')

  const load = useCallback(
    () =>
      api
        .get(`/api/v1/customer/loans/${loanId}`)
        .then(({ data }) => setLoan(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [loanId],
  )

  useEffect(() => {
    load()
  }, [load])

  if (loadError) return <Alert>{loadError}</Alert>
  if (!loan) return <Loading />

  const hasSchedule = ['ACTIVE', 'CLOSED'].includes(loan.status) && loan.schedule.length > 0

  return (
    <section className="space-y-6">
      <div>
        <Link to="/customer/loans" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Loans
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">{loan.loan_type.type_name} loan</h1>
          <LoanStatusBadge status={loan.status} />
        </div>
        <p className="mt-1 text-sm text-slate-600">Applied {formatDateTime(loan.application_date)}</p>
      </div>

      <Alert tone="success">{flash}</Alert>
      {loan.status === 'REJECTED' && loan.rejection_reason && <Alert>Not approved: {loan.rejection_reason}</Alert>}

      <Panel
        title="Summary"
        action={
          loan.can_cancel && (
            <Button variant="secondary" className="py-1.5" onClick={() => setCancelling(true)}>
              Cancel application
            </Button>
          )
        }
      >
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
          <LoanTermsFields loan={loan} />
          <Field label="Paid into account" value={<span className="font-mono">{loan.account_number}</span>} />
          <Field label="Purpose" value={PURPOSE_LABELS[loan.purpose_category]} />
          {loan.staff_approved_at && <Field label="Approved by your branch" value={formatDateTime(loan.staff_approved_at)} />}
          {loan.approval_date && <Field label="Final approval and payout" value={formatDateTime(loan.approval_date)} />}
          {loan.rejected_at && <Field label="Decided" value={formatDateTime(loan.rejected_at)} />}
          {loan.cancelled_at && <Field label="Cancelled" value={formatDateTime(loan.cancelled_at)} />}
          {loan.closed_at && <Field label="Closed" value={formatDate(loan.closed_at)} />}
        </dl>
      </Panel>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-navy-900">Repayment schedule</h2>
        {hasSchedule ? (
          <ScheduleTable schedule={loan.schedule} />
        ) : (
          <p className="text-sm text-slate-600">
            {['PENDING', 'AWAITING_ADMIN'].includes(loan.status)
              ? 'Your schedule appears here once the loan is approved.'
              : 'This loan has no repayment schedule.'}
          </p>
        )}
      </div>

      {cancelling && (
        <ConfirmDialog
          title="Cancel this application?"
          confirmLabel="Cancel application"
          busyLabel="Cancelling…"
          onClose={() => setCancelling(false)}
          onConfirm={async () => {
            const { data } = await api.post(`/api/v1/customer/loans/${loan.loan_id}/cancel`)
            setCancelling(false)
            setFlash(data.message)
            load()
          }}
        >
          <p>
            Your application for <strong>{formatMoney(loan.amount)}</strong> stops here and the bank won&apos;t review it further. You can
            apply again afterwards.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}
