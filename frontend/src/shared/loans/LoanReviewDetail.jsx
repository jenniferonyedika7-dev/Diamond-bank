import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import Modal from '../../components/Modal.jsx'
import Pagination from '../../components/Pagination.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import { LoanStatusBadge } from '../../components/StatusBadges.jsx'
import TransactionList from '../../components/TransactionList.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDate, formatDateTime } from '../../lib/format.js'
import { EMPLOYMENT_FIELD_LABELS, EMPLOYMENT_LABELS, PURPOSE_LABELS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { useApiList } from '../../lib/useApiList.js'
import { Field, LoanTermsFields, Panel } from './LoanParts.jsx'
import ScheduleTable from './ScheduleTable.jsx'

/**
 * One loan for staff (their branch: checks, first approval, reject) or an
 * admin (every branch: read-only checks, approve and disburse, reject).
 * Every figure, including affordability, comes from the API.
 */
export default function LoanReviewDetail({ area }) {
  const { loanId } = useParams()
  const [loan, setLoan] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [dialog, setDialog] = useState(null) // { type: 'check', check } | { type: 'approve' } | { type: 'reject' }
  const [flash, setFlash] = useState('')
  const base = `/api/v1/${area}/loans/${loanId}`

  const load = useCallback(
    () =>
      api
        .get(base)
        .then(({ data }) => setLoan(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [base],
  )

  useEffect(() => {
    load()
  }, [load])

  function done(message) {
    setDialog(null)
    setFlash(message)
    load()
  }

  if (loadError) return <Alert>{loadError}</Alert>
  if (!loan) return <Loading />

  const isStaff = area === 'staff'
  const canDecide = isStaff ? loan.status === 'PENDING' : loan.status === 'AWAITING_ADMIN'
  const missing = loan.verifications.filter((v) => !v.recorded)

  return (
    <section className="space-y-6">
      <div>
        <Link to={`/${area}/loans`} className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Loans
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">
            Loan #{loan.loan_id}: {loan.customer.name}
          </h1>
          <LoanStatusBadge status={loan.status} />
        </div>
        <p className="mt-1 text-sm text-slate-600">
          {loan.loan_type.type_name} · {loan.branch.branch_name} · applied {formatDateTime(loan.application_date)}
        </p>
      </div>

      <Alert tone="success">{flash}</Alert>

      {canDecide && (
        <Panel title="Decision">
          {isStaff && missing.length > 0 && (
            <p className="mb-3 text-sm text-slate-600" id="approve-blocked">
              Record these checks before approving: {missing.map((v) => v.label).join(', ')}.
            </p>
          )}
          <div className="flex flex-wrap gap-2">
            <Button
              onClick={() => setDialog({ type: 'approve' })}
              disabled={isStaff && missing.length > 0}
              aria-describedby={isStaff && missing.length > 0 ? 'approve-blocked' : undefined}
            >
              {isStaff ? 'Approve' : 'Approve and disburse'}
            </Button>
            <Button variant="secondary" onClick={() => setDialog({ type: 'reject' })}>
              Reject
            </Button>
          </div>
        </Panel>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel title="Loan">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <LoanTermsFields loan={loan} />
            <Field label="Account to credit" value={<span className="font-mono">{loan.account_number}</span>} />
            <Field label="Purpose" value={PURPOSE_LABELS[loan.application.purpose_category]} />
          </dl>
          <p className="mt-3 text-sm text-slate-700">
            <span className="text-slate-500">Description: </span>
            {loan.application.purpose_description}
          </p>
        </Panel>

        <Affordability affordability={loan.affordability} />

        <Panel title="Applicant">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="Monthly income" value={formatMoney(loan.application.monthly_income)} />
            <Field label="TIN" value={<span className="font-mono">{loan.tin}</span>} />
            <Field label="Employment" value={EMPLOYMENT_LABELS[loan.application.employment_type]} />
            {Object.entries(EMPLOYMENT_FIELD_LABELS)
              .filter(([key]) => loan.application[key])
              .map(([key, label]) => (
                <Field key={key} label={label} value={loan.application[key]} />
              ))}
            <Field label="Phone (from profile)" value={loan.application.contact_phone} />
            <Field label="Email (from profile)" value={loan.application.contact_email} />
          </dl>
        </Panel>

        <Panel title="Guarantor">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="Full name" value={loan.guarantor.full_name} />
            <Field label="Occupation" value={loan.guarantor.occupation} />
            <Field label="Phone" value={loan.guarantor.phone} />
            <Field label="Email" value={loan.guarantor.email} />
            <Field label="Address" value={loan.guarantor.address} />
          </dl>
        </Panel>
      </div>

      <Panel title="Verification checks">
        {!isStaff && <p className="mb-3 text-sm text-slate-600">Recorded by branch staff before their approval.</p>}
        <ul className="divide-y divide-slate-100">
          {loan.verifications.map((check) => (
            <li key={check.check_type} className="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="min-w-0 text-sm">
                <p className="flex items-center gap-2 font-medium text-slate-900">
                  <span aria-hidden="true" className={check.recorded ? 'text-emerald-700' : 'text-slate-400'}>
                    {check.recorded ? '✓' : '○'}
                  </span>
                  {check.label}
                  <span className="sr-only">{check.recorded ? ': done' : ': not done'}</span>
                </p>
                {check.recorded ? (
                  <>
                    <p className="mt-1 whitespace-pre-line break-words text-slate-700">{check.note}</p>
                    <p className="mt-1 text-xs text-slate-500">
                      {check.verified_by.name} · {formatDateTime(check.verified_at)}
                    </p>
                  </>
                ) : (
                  <p className="mt-1 text-slate-500">Not done yet.</p>
                )}
              </div>
              {isStaff && loan.status === 'PENDING' && (
                <Button variant="secondary" className="shrink-0 py-1.5" onClick={() => setDialog({ type: 'check', check })}>
                  {check.recorded ? 'Update' : 'Record'}
                  <span className="sr-only"> {check.label}</span>
                </Button>
              )}
            </li>
          ))}
        </ul>
      </Panel>

      <DecisionHistory loan={loan} />

      {['ACTIVE', 'CLOSED'].includes(loan.status) && loan.schedule.length > 0 && (
        <div>
          <h2 className="mb-3 text-lg font-semibold text-navy-900">Repayment schedule</h2>
          <ScheduleTable schedule={loan.schedule} />
        </div>
      )}

      <Statement endpoint={`${base}/statement`} />

      {dialog?.type === 'check' && (
        <CheckDialog
          check={dialog.check}
          onClose={() => setDialog(null)}
          onSubmit={async (note) => done((await api.put(`${base}/verifications/${dialog.check.check_type}`, { note })).data.message)}
        />
      )}
      {dialog?.type === 'approve' && isStaff && (
        <ConfirmDialog
          title="Approve this application?"
          confirmLabel="Approve"
          busyLabel="Approving…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => done((await api.post(`${base}/approve`)).data.message)}
        >
          <p>
            The loan of <strong>{formatMoney(loan.amount)}</strong> for <strong>{loan.customer.name}</strong> goes to an administrator
            for the final approval. No money moves yet.
          </p>
        </ConfirmDialog>
      )}
      {dialog?.type === 'approve' && !isStaff && (
        <ConfirmDialog
          title="Approve and pay out this loan?"
          confirmLabel="Approve and disburse"
          busyLabel="Disbursing…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => done((await api.post(`${base}/approve`)).data.message)}
        >
          <p>
            <strong>{formatMoney(loan.amount)}</strong> will be paid into account <span className="font-mono">{loan.account_number}</span>{' '}
            ({loan.customer.name}) now, and the repayment schedule starts today. This can&apos;t be undone.
          </p>
        </ConfirmDialog>
      )}
      {dialog?.type === 'reject' && (
        <ReasonDialog
          title={`Reject loan #${loan.loan_id}: ${loan.customer.name}`}
          intro="The customer sees this reason on their Loans page."
          submitLabel="Reject application"
          busyLabel="Rejecting…"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => done((await api.post(`${base}/reject`, { reason })).data.message)}
        />
      )}
    </section>
  )
}

function Affordability({ affordability }) {
  return (
    <Panel title="Affordability">
      <p className="text-3xl font-semibold tabular-nums text-navy-900">{affordability.percent_of_income}%</p>
      <p className="mt-1 text-sm text-slate-600">
        of the declared monthly income goes on the monthly repayment of {formatMoney(affordability.monthly_repayment)} (income{' '}
        {formatMoney(affordability.monthly_income)}).
      </p>
      {affordability.above_warning ? (
        <div role="status" className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
          Above the {affordability.warning_percent}% warning level. This is a warning, not a rule: weigh it with the statement and the
          checks before deciding.
        </div>
      ) : (
        <p className="mt-4 text-sm text-slate-600">Within the {affordability.warning_percent}% warning level.</p>
      )}
    </Panel>
  )
}

function DecisionHistory({ loan }) {
  const rows = [
    loan.staff_approval && ['Staff approval', loan.staff_approval],
    loan.admin_approval && ['Admin approval and disbursement', loan.admin_approval],
    loan.rejection && ['Rejected', loan.rejection],
  ].filter(Boolean)
  if (rows.length === 0 && !loan.cancelled_at) return null

  return (
    <Panel title="Decisions">
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
        {rows.map(([label, person]) => (
          <Field key={label} label={label} value={`${person.name ?? 'Unknown'} · ${formatDateTime(person.at)}`} />
        ))}
        {loan.cancelled_at && <Field label="Cancelled by the customer" value={formatDateTime(loan.cancelled_at)} />}
        {loan.closed_at && <Field label="Closed" value={formatDate(loan.closed_at)} />}
      </dl>
      {loan.rejection_reason && (
        <p className="mt-3 text-sm text-slate-700">
          <span className="text-slate-500">Reason: </span>
          {loan.rejection_reason}
        </p>
      )}
    </Panel>
  )
}

function Statement({ endpoint }) {
  const [page, setPage] = useState(1)
  const { items, pagination, extra, loading, error } = useApiList(endpoint, { page })

  return (
    <div>
      <h2 className="mb-1 text-lg font-semibold text-navy-900">Statement</h2>
      <p className="mb-3 text-sm text-slate-600">
        The customer&apos;s transactions on all their accounts{extra.months ? ` over the last ${extra.months} months` : ''}.
      </p>
      {loading ? (
        <Loading />
      ) : error ? (
        <Alert>{error}</Alert>
      ) : items.length === 0 ? (
        <EmptyState title="No transactions in this period." />
      ) : (
        <>
          <TransactionList transactions={items} showAccount />
          <Pagination pagination={pagination} onPageChange={setPage} />
        </>
      )}
    </div>
  )
}

/** Records or updates one check. The note is required; a correction keeps the old note in the audit log. */
function CheckDialog({ check, onSubmit, onClose }) {
  const [note, setNote] = useState(check.note ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      await onSubmit(note)
    } catch (err) {
      const fields = getFieldErrors(err)
      setFieldErrors(fields)
      if (!fields.note) setError(getErrorMessage(err))
      setSubmitting(false)
    }
  }

  return (
    <Modal title={check.label} onClose={onClose}>
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <p className="text-sm text-slate-600">
          {check.recorded ? 'Update the note. The previous note stays in the audit log.' : 'Say what you checked and what you found.'}
        </p>
        <Alert>{error}</Alert>
        <FormField
          id="note"
          as="textarea"
          label="Note"
          required
          maxLength={500}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          hint={`${note.length}/500 characters.`}
          error={fieldErrors.note}
        />
        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting} loadingText="Saving…" disabled={note.trim() === ''}>
            {check.recorded ? 'Update check' : 'Record check'}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
