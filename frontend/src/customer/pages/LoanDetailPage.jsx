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
import PaymentHistory from '../../shared/loans/PaymentHistory.jsx'
import RepaymentSummary from '../../shared/loans/RepaymentSummary.jsx'
import ScheduleTable from '../../shared/loans/ScheduleTable.jsx'

const LINK_BUTTON =
  'inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm focus-visible:outline-2 focus-visible:outline-offset-2'

export default function LoanDetailPage() {
  const { loanId } = useParams()
  const [loan, setLoan] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [cancelling, setCancelling] = useState(false)
  const [flash, setFlash] = useState('')
  const [quote, setQuote] = useState(null)
  const [quoteError, setQuoteError] = useState('')

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

  // The repayment quote exists only for an ACTIVE loan (the API answers 409 otherwise).
  const canPay = loan?.can_pay ?? false
  useEffect(() => {
    if (!canPay) return
    let ignore = false
    api
      .get(`/api/v1/customer/loans/${loanId}/repayment`)
      .then(({ data }) => {
        if (ignore) return
        setQuote(data.data)
        setQuoteError('')
      })
      .catch((err) => !ignore && setQuoteError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [canPay, loanId])

  if (loadError) return <Alert>{loadError}</Alert>
  if (!loan) return <Loading />

  const repaying = ['ACTIVE', 'CLOSED'].includes(loan.status)
  const hasSchedule = repaying && loan.schedule.length > 0

  return (
    <section className="space-y-6">
      <div>
        <Link to="/customer/loans" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Loans
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">{loan.loan_type.type_name}</h1>
          <LoanStatusBadge status={loan.status} />
        </div>
        <p className="mt-1 text-sm text-slate-600">Applied {formatDateTime(loan.application_date)}</p>
      </div>

      <Alert tone="success">{flash}</Alert>
      {loan.status === 'REJECTED' && loan.rejection_reason && <Alert>Not approved: {loan.rejection_reason}</Alert>}

      {loan.status === 'CLOSED' && (
        <Alert tone="success">
          This loan is paid off{loan.closed_at ? ` and was closed on ${formatDate(loan.closed_at)}` : ''}. There is nothing more to pay.
        </Alert>
      )}

      {loan.can_pay && (
        <Panel title="Repayment">
          {quoteError ? (
            <Alert>{quoteError}</Alert>
          ) : !quote ? (
            <Loading />
          ) : (
            <RepaymentSummary
              quote={quote}
              actions={
                <>
                  {quote.next_instalment && (
                    <Link
                      to={`/customer/loans/${loan.loan_id}/pay?type=INSTALMENT`}
                      className={`${LINK_BUTTON} bg-navy-900 text-white hover:bg-navy-800 focus-visible:outline-accent-500`}
                    >
                      Pay next instalment
                    </Link>
                  )}
                  {quote.payoff && (
                    <Link
                      to={`/customer/loans/${loan.loan_id}/pay?type=EARLY_PAYOFF`}
                      className={`${LINK_BUTTON} ${
                        quote.next_instalment
                          ? 'bg-white text-navy-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-navy-600'
                          : 'bg-navy-900 text-white hover:bg-navy-800 focus-visible:outline-accent-500'
                      }`}
                    >
                      Pay everything now
                    </Link>
                  )}
                </>
              }
            />
          )}
        </Panel>
      )}

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

      {repaying && (
        <div>
          <h2 className="mb-3 text-lg font-semibold text-navy-900">Payment history</h2>
          <PaymentHistory payments={loan.payments} />
        </div>
      )}

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
