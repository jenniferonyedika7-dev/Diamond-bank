import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import Loading from '../../components/Loading.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import { AccountStatusBadge, KycBadge, LoginStatusBadge } from '../../components/StatusBadges.jsx'
import { api, getErrorMessage } from '../../lib/api.js'
import { formatDate, formatDateTime } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { CHANNEL_LABELS, RECOVERY_LABELS, STATUS_TONES, TYPE_LABELS } from '../../shared/access/labels.js'

const CHECK_LABELS = {
  dob_confirmed: 'Date of birth confirmed',
  phone_confirmed: 'Registered phone number confirmed',
  history_reviewed: 'Account and transaction history reviewed',
  declaration_confirmed: 'Declared: the customer contacted the branch and their identity was verified',
}

export default function AccessRequestDetailPage() {
  const { id } = useParams()
  const [request, setRequest] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [dialog, setDialog] = useState(null) // 'approve' | 'reject'
  const [flash, setFlash] = useState('')

  const load = useCallback(
    () =>
      api
        .get(`/api/v1/admin/access-requests/${id}`)
        .then(({ data }) => setRequest(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [id],
  )

  useEffect(() => {
    load()
  }, [load])

  if (loadError) return <Alert>{loadError}</Alert>
  if (!request) return <Loading />

  const { customer, login } = request
  const reset = request.request_type === 'UNBLOCK_AND_RESET'

  async function decide(path, body) {
    const { data } = await api.post(`/api/v1/admin/access-requests/${id}/${path}`, body)
    setDialog(null)
    setFlash(data.message)
    load()
  }

  return (
    <section className="space-y-6">
      <div>
        <Link to="/admin/access-requests" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Access requests
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">
            Access request: {customer.first_name} {customer.last_name}
          </h1>
          <Badge tone={STATUS_TONES[request.status]}>{request.status.charAt(0) + request.status.slice(1).toLowerCase()}</Badge>
        </div>
      </div>

      <Alert tone="success">{flash}</Alert>

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel title="Request">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="Needs" value={TYPE_LABELS[request.request_type]} />
            <Field label="Why" value={RECOVERY_LABELS[request.reason]} />
            <Field label="Contact" value={CHANNEL_LABELS[request.contact_channel]} />
            <Field label="Branch" value={request.branch_name} />
            <Field label="Raised by" value={request.requested_by_name} />
            <Field label="Raised on" value={formatDateTime(request.requested_at)} />
            {request.reviewed_at && <Field label="Decided by" value={request.reviewed_by_name} />}
            {request.reviewed_at && <Field label="Decided on" value={formatDateTime(request.reviewed_at)} />}
          </dl>
          <h3 className="mt-4 text-sm font-semibold text-slate-700">Staff checks</h3>
          <ul className="mt-2 space-y-1 text-sm">
            {Object.entries(CHECK_LABELS).map(([key, label]) => (
              <li key={key} className="flex gap-2">
                <span aria-hidden="true" className={request.checks[key] ? 'text-emerald-700' : 'text-red-700'}>
                  {request.checks[key] ? '✓' : '✗'}
                </span>
                <span className="sr-only">{request.checks[key] ? 'Done:' : 'Not done:'}</span>
                {label}
              </li>
            ))}
          </ul>
          <h3 className="mt-4 text-sm font-semibold text-slate-700">Notes</h3>
          <p className="mt-1 whitespace-pre-line text-sm text-slate-800">{request.notes}</p>
          {request.rejection_reason && (
            <>
              <h3 className="mt-4 text-sm font-semibold text-slate-700">Rejection reason</h3>
              <p className="mt-1 text-sm text-slate-800">{request.rejection_reason}</p>
            </>
          )}
        </Panel>

        <Panel title="Customer">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="Name" value={`${customer.first_name} ${customer.last_name}`} />
            <Field label="Date of birth" value={formatDate(customer.date_of_birth)} />
            <Field label="National ID" value={customer.national_id} />
            <Field label="Registered phone" value={customer.phone} />
            <Field label="Email" value={customer.email} />
            <Field label="Home branch" value={customer.branch?.branch_name} />
            <Field label="KYC" value={<KycBadge status={customer.kyc_status} />} />
            <Field
              label="Login"
              value={
                <>
                  @{login.user_name} <LoginStatusBadge status={login.status} />
                </>
              }
            />
          </dl>
          {login.recovery && <p className="mt-3 text-sm font-medium text-amber-800">{RECOVERY_LABELS[login.recovery]}</p>}
          <h3 className="mt-4 text-sm font-semibold text-slate-700">Accounts</h3>
          {customer.accounts.length === 0 ? (
            <p className="mt-1 text-sm text-slate-600">No accounts.</p>
          ) : (
            <ul className="mt-1 divide-y divide-slate-100 text-sm">
              {customer.accounts.map((a) => (
                <li key={a.account_id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span className="font-mono">{a.account_number}</span>
                  <span className="flex items-center gap-3">
                    <AccountStatusBadge status={a.status} />
                    <span className="tabular-nums">{formatMoney(a.balance, a.currency_code)}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      {request.status === 'PENDING' && (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setDialog('approve')}>Approve</Button>
          <Button variant="secondary" onClick={() => setDialog('reject')}>
            Reject
          </Button>
        </div>
      )}

      {dialog === 'approve' && (
        <ConfirmDialog
          title="Approve access request?"
          confirmLabel="Approve"
          busyLabel="Approving…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={() => decide('approve')}
        >
          <p>
            <strong>
              {customer.first_name} {customer.last_name}
            </strong>{' '}
            will be able to sign in again.
          </p>
          <p>
            {reset
              ? `The system texts a temporary password to ${customer.phone}. Nobody at the bank sees it, and the customer must change it when they sign in.`
              : `They keep their own password and get an SMS at ${customer.phone} saying their account is unblocked.`}
          </p>
        </ConfirmDialog>
      )}
      {dialog === 'reject' && (
        <ReasonDialog
          title="Reject access request"
          intro="The login stays blocked, and the customer gets an SMS asking them to visit their branch."
          submitLabel="Reject request"
          busyLabel="Rejecting…"
          onClose={() => setDialog(null)}
          onSubmit={(reason) => decide('reject', { reason })}
        />
      )}
    </section>
  )
}

function Panel({ title, children }) {
  return (
    <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 className="mb-4 text-lg font-semibold text-navy-900">{title}</h2>
      {children}
    </div>
  )
}

function Field({ label, value }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-slate-900">{value || '—'}</dd>
    </div>
  )
}
