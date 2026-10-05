import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import Modal from '../../components/Modal.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDate, formatDateTime } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { AccountStatusBadge, CardStatusBadge, KycBadge, LoginStatusBadge } from '../../components/StatusBadges.jsx'
import TransactionList from '../../components/TransactionList.jsx'

const GENDER = { M: 'Male', F: 'Female' }

export default function CustomerDetailPage() {
  const { id } = useParams()
  const [customer, setCustomer] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [dialog, setDialog] = useState(null) // 'edit' | 'verify' | 'reject' | 'block' | 'unblock' | 'open'
  const [flash, setFlash] = useState('')

  const load = useCallback(
    () =>
      api
        .get(`/api/v1/staff/customers/${id}`)
        .then(({ data }) => setCustomer(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [id],
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
  if (!customer) return <Loading />

  const post = (path, body) => api.post(`/api/v1/staff/customers/${customer.customer_id}/${path}`, body)
  const fullName = `${customer.first_name} ${customer.last_name}`

  return (
    <section className="space-y-6">
      <div>
        <Link to="/staff/customers" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Customers
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">{fullName}</h1>
          <KycBadge status={customer.kyc_status} />
        </div>
      </div>

      <Alert tone="success">{flash}</Alert>

      <div className="grid gap-6 lg:grid-cols-3">
        {/* Profile */}
        <Card title="Profile" action={<Button variant="secondary" className="py-1.5" onClick={() => setDialog('edit')}>Edit</Button>} className="lg:col-span-2">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="National ID" value={customer.national_id} />
            <Field label="Date of birth" value={formatDate(customer.date_of_birth)} />
            <Field label="Gender" value={GENDER[customer.gender]} />
            <Field label="Home branch" value={customer.branch?.branch_name} />
            <Field label="Phone" value={customer.phone} />
            <Field label="Email" value={customer.email} />
            <Field label="Registered" value={formatDate(customer.registration_date)} />
          </dl>
          {customer.addresses.length > 0 && (
            <div className="mt-4 border-t border-slate-100 pt-4">
              <h3 className="text-sm font-semibold text-slate-700">Addresses</h3>
              <ul className="mt-2 space-y-1 text-sm text-slate-700">
                {customer.addresses.map((a) => (
                  <li key={a.customer_address_id}>
                    <span className="font-medium">{a.address_type}:</span> {a.city_street}, {a.country_region}
                    {a.postal_code && ` ${a.postal_code}`}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>

        <div className="space-y-6">
          {/* KYC */}
          <Card title="KYC">
            <p className="text-sm text-slate-700">
              Status: <KycBadge status={customer.kyc_status} />
            </p>
            {customer.kyc_status === 'VERIFIED' && (
              <p className="mt-2 text-sm text-slate-600">
                Verified by {customer.verified_by_name ?? 'unknown'} on {formatDateTime(customer.verified_at)}.
              </p>
            )}
            {customer.kyc_status === 'PENDING' && (
              <div className="mt-4 flex flex-wrap gap-2">
                <Button onClick={() => setDialog('verify')}>Verify</Button>
                <Button variant="secondary" onClick={() => setDialog('reject')}>
                  Reject
                </Button>
              </div>
            )}
          </Card>

          {/* Login */}
          <Card title="Online login">
            {customer.login ? (
              <>
                <p className="text-sm text-slate-700">
                  @{customer.login.user_name} · <LoginStatusBadge status={customer.login.status} />
                </p>
                <p className="mt-1 text-xs text-slate-500">
                  Last sign-in: {customer.login.last_login ? formatDateTime(customer.login.last_login) : 'never'}
                </p>
                <div className="mt-4">
                  {customer.login.status === 'ACTIVE' && (
                    <Button variant="secondary" onClick={() => setDialog('block')}>
                      Block login
                    </Button>
                  )}
                  {customer.login.status === 'BLOCKED' && (
                    <Button variant="secondary" onClick={() => setDialog('unblock')}>
                      Unblock login
                    </Button>
                  )}
                </div>
              </>
            ) : (
              <p className="text-sm text-slate-600">This customer has no online login.</p>
            )}
          </Card>
        </div>
      </div>

      {/* Accounts */}
      <Card
        title="Accounts"
        action={
          <Button
            className="py-1.5"
            onClick={() => setDialog('open')}
            disabled={customer.kyc_status !== 'VERIFIED'}
            aria-describedby={customer.kyc_status !== 'VERIFIED' ? 'open-account-note' : undefined}
          >
            Open account
          </Button>
        }
      >
        {customer.kyc_status !== 'VERIFIED' && (
          <p id="open-account-note" className="mb-3 text-sm text-slate-600">
            Accounts can be opened once the customer's KYC is verified.
          </p>
        )}
        {customer.accounts.length === 0 ? (
          <p className="text-sm text-slate-600">No accounts yet.</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {customer.accounts.map((a) => (
              <li key={a.account_id}>
                <Link
                  to={`/staff/accounts/${a.account_number}`}
                  className="-mx-2 flex flex-wrap items-center justify-between gap-2 rounded-lg px-2 py-3 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-navy-600"
                >
                  <span>
                    <span className="font-mono text-sm text-slate-900">{a.account_number}</span>
                    <span className="ml-2 text-sm text-slate-600">{a.type_name}</span>
                  </span>
                  <span className="flex items-center gap-3">
                    <AccountStatusBadge status={a.status} />
                    <span className="font-medium tabular-nums text-slate-900">{formatMoney(a.balance)}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* Cards (issue, reject and unblock on the Cards page) */}
      <Card
        title="Cards"
        action={
          <Link to="/staff/cards" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
            Card requests
          </Link>
        }
      >
        {customer.cards.length === 0 ? (
          <p className="text-sm text-slate-600">No cards or card requests.</p>
        ) : (
          <ul className="divide-y divide-slate-100">
            {customer.cards.map((c) => (
              <li key={c.bank_card_id} className="flex flex-wrap items-center justify-between gap-2 py-3">
                <span className="text-sm">
                  <span className="text-slate-900">{c.card_type.type_name}</span>
                  <span className="ml-2 font-mono text-slate-600">{c.masked_number ?? 'not issued'}</span>
                  <span className="ml-2 font-mono text-xs text-slate-500">{c.account_number}</span>
                </span>
                <span className="flex items-center gap-3 text-xs text-slate-500">
                  {c.expiry_date && `Expires ${formatDate(c.expiry_date)}`}
                  <CardStatusBadge status={c.status} />
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {/* Recent transactions */}
      <div>
        <h2 className="mb-3 text-lg font-semibold text-navy-900">Recent transactions</h2>
        {customer.recent_transactions.length === 0 ? (
          <p className="text-sm text-slate-600">No transactions yet.</p>
        ) : (
          <TransactionList transactions={customer.recent_transactions} showAccount />
        )}
      </div>

      {dialog === 'edit' && <EditCustomerDialog customer={customer} onClose={() => setDialog(null)} onDone={done} />}
      {dialog === 'open' && <OpenAccountDialog customer={customer} onClose={() => setDialog(null)} />}
      {dialog === 'verify' && (
        <ConfirmDialog
          title="Verify customer?"
          confirmLabel="Verify"
          busyLabel="Verifying…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => done((await post('verify')).data.message)}
        >
          <p>
            Confirm you have checked <strong>{fullName}</strong>'s identity document (national ID {customer.national_id}).
            Accounts can then be opened for them.
          </p>
        </ConfirmDialog>
      )}
      {dialog === 'reject' && (
        <ReasonDialog
          title={`Reject KYC: ${fullName}`}
          intro="No accounts can be opened for a customer whose KYC is rejected."
          submitLabel="Reject KYC"
          busyLabel="Rejecting…"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => done((await post('reject-kyc', { reason })).data.message)}
        />
      )}
      {dialog === 'block' && (
        <ReasonDialog
          title={`Block login: ${fullName}`}
          intro="They are signed out straight away and can't sign in until unblocked. Their accounts are not affected."
          submitLabel="Block login"
          busyLabel="Blocking…"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => done((await post('block', { reason })).data.message)}
        />
      )}
      {dialog === 'unblock' && (
        <ConfirmDialog
          title="Unblock login?"
          confirmLabel="Unblock"
          busyLabel="Unblocking…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => done((await post('unblock')).data.message)}
        >
          <p>
            <strong>{fullName}</strong> will be able to sign in again.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function Card({ title, action, children, className = '' }) {
  return (
    <div className={`rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 ${className}`}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-navy-900">{title}</h2>
        {action}
      </div>
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

const EDITABLE = ['first_name', 'last_name', 'date_of_birth', 'gender', 'national_id', 'phone', 'email', 'branch_id']

function EditCustomerDialog({ customer, onClose, onDone }) {
  const [form, setForm] = useState(() => Object.fromEntries(EDITABLE.map((k) => [k, customer[k] ?? ''])))
  const [branches, setBranches] = useState([])
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const idLocked = customer.kyc_status !== 'PENDING'

  useEffect(() => {
    let ignore = false
    api.get('/api/v1/branches').then(({ data }) => !ignore && setBranches(data.data))
    return () => {
      ignore = true
    }
  }, [])

  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))
  const field = (id) => ({ id, value: form[id], onChange: update, error: fieldErrors[id], required: true })

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.put(`/api/v1/staff/customers/${customer.customer_id}`, { ...form, branch_id: Number(form.branch_id) })
      onDone(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  return (
    <Modal title="Edit customer details" onClose={onClose} size="lg">
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <Alert>{error}</Alert>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="First name" maxLength={100} {...field('first_name')} />
          <FormField label="Last name" maxLength={100} {...field('last_name')} />
          <FormField label="Date of birth" type="date" {...field('date_of_birth')} />
          <FormField label="Gender" {...field('gender')}>
            <option value="F">Female</option>
            <option value="M">Male</option>
          </FormField>
          <FormField
            label="National ID number"
            maxLength={30}
            readOnly={idLocked}
            hint={idLocked ? 'Locked: the national ID can only be changed while KYC is pending.' : undefined}
            {...field('national_id')}
          />
          <FormField label="Phone number" type="tel" maxLength={20} {...field('phone')} />
          <FormField label="Email address" type="email" maxLength={150} {...field('email')} />
          <FormField label="Home branch" {...field('branch_id')}>
            {branches.length === 0 && <option value={form.branch_id}>{customer.branch?.branch_name}</option>}
            {branches.map((b) => (
              <option key={b.branch_id} value={b.branch_id}>
                {b.branch_name}
              </option>
            ))}
          </FormField>
        </div>
        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting} loadingText="Saving…">
            Save changes
          </Button>
        </div>
      </form>
    </Modal>
  )
}

function OpenAccountDialog({ customer, onClose }) {
  const navigate = useNavigate()
  const [types, setTypes] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [form, setForm] = useState({ account_type_id: '', initial_deposit: '' })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/staff/account-types')
      .then(({ data }) => !ignore && setTypes(data.data))
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [])

  const selected = types?.find((t) => String(t.account_type_id) === String(form.account_type_id))
  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post('/api/v1/staff/accounts', {
        customer_id: customer.customer_id,
        account_type_id: form.account_type_id === '' ? null : Number(form.account_type_id),
        initial_deposit: form.initial_deposit,
        currency_code: 'GMD',
      })
      navigate(`/staff/accounts/${data.data.account_number}`, { state: { flash: data.message } })
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  return (
    <Modal title={`Open account: ${customer.first_name} ${customer.last_name}`} onClose={onClose}>
      {loadError ? (
        <Alert>{loadError}</Alert>
      ) : !types ? (
        <Loading label="Loading account types…" />
      ) : types.length === 0 ? (
        <Alert tone="info">No account types are set up yet. Ask an administrator to add one.</Alert>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          <p className="text-sm text-slate-600">The account is opened at your branch, in GMD.</p>
          <Alert>{error}</Alert>
          <FormField id="account_type_id" label="Account type" required value={form.account_type_id} onChange={update} error={fieldErrors.account_type_id}>
            <option value="">Select a type…</option>
            {types.map((t) => (
              <option key={t.account_type_id} value={t.account_type_id}>
                {t.type_name}: minimum {formatMoney(t.minimum_balance)}
              </option>
            ))}
          </FormField>
          <FormField
            id="initial_deposit"
            label="Initial deposit (GMD)"
            type="number"
            inputMode="decimal"
            step="0.01"
            min={selected?.minimum_balance ?? 0}
            required
            value={form.initial_deposit}
            onChange={update}
            error={fieldErrors.initial_deposit}
            hint={selected ? `At least ${formatMoney(selected.minimum_balance)} (the minimum balance).` : 'Choose an account type to see its minimum.'}
          />
          <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={submitting} loadingText="Opening…">
              Open account
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
