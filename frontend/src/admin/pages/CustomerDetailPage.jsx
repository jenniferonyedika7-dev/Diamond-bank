import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import Loading from '../../components/Loading.jsx'
import { AccountStatusBadge, KycBadge, LoginStatusBadge } from '../../components/StatusBadges.jsx'
import { api, getErrorMessage } from '../../lib/api.js'
import { formatDate, formatDateTime } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import UsernameDialog from '../UsernameDialog.jsx'

const GENDER = { M: 'Male', F: 'Female' }

/** Read-only, apart from renaming the customer's login. */
export default function CustomerDetailPage() {
  const { id } = useParams()
  const [customer, setCustomer] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [renaming, setRenaming] = useState(false)
  const [flash, setFlash] = useState('')

  const load = useCallback(
    () =>
      api
        .get(`/api/v1/admin/customers/${id}`)
        .then(({ data }) => setCustomer(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [id],
  )

  useEffect(() => {
    load()
  }, [load])

  if (loadError) return <Alert>{loadError}</Alert>
  if (!customer) return <Loading />

  const fullName = `${customer.first_name} ${customer.last_name}`

  return (
    <section className="space-y-6">
      <div>
        <Link to="/admin/customers" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Customers
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">{fullName}</h1>
          <KycBadge status={customer.kyc_status} />
        </div>
      </div>

      <Alert tone="success">{flash}</Alert>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card title="Profile" className="lg:col-span-2">
          <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <Field label="National ID" value={customer.national_id} />
            <Field label="Date of birth" value={formatDate(customer.date_of_birth)} />
            <Field label="Gender" value={GENDER[customer.gender]} />
            <Field label="Home branch" value={customer.branch?.branch_name} />
            <Field label="Phone" value={customer.phone} />
            <Field label="Email" value={customer.email} />
            <Field label="Registered" value={formatDate(customer.registration_date)} />
          </dl>
        </Card>

        <Card title="Online login">
          {customer.login ? (
            <>
              <p className="text-sm text-slate-700">
                @{customer.login.user_name} · <LoginStatusBadge status={customer.login.status} />
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Last sign-in: {customer.login.last_login ? formatDateTime(customer.login.last_login) : 'never'}
              </p>
              <Button variant="secondary" className="mt-4" onClick={() => setRenaming(true)}>
                Change username
              </Button>
            </>
          ) : (
            <p className="text-sm text-slate-600">This customer has no online login.</p>
          )}
        </Card>
      </div>

      <Card title="Accounts">
        {customer.accounts.length === 0 ? (
          <p className="text-sm text-slate-600">No accounts yet.</p>
        ) : (
          <div className="-mx-5 overflow-x-auto">
            <table className="min-w-full text-sm">
              <caption className="sr-only">Accounts held by {fullName}</caption>
              <thead>
                <tr className="border-b border-slate-200 text-left text-slate-500">
                  <th scope="col" className="px-5 py-2 font-medium">Account number</th>
                  <th scope="col" className="px-5 py-2 font-medium">Type</th>
                  <th scope="col" className="px-5 py-2 font-medium">Branch</th>
                  <th scope="col" className="px-5 py-2 font-medium">Status</th>
                  <th scope="col" className="px-5 py-2 text-right font-medium">Balance</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {customer.accounts.map((a) => (
                  <tr key={a.account_id}>
                    <td className="px-5 py-3 font-mono text-slate-900">{a.account_number}</td>
                    <td className="px-5 py-3 text-slate-700">{a.type_name}</td>
                    <td className="px-5 py-3 text-slate-700">{a.branch_name}</td>
                    <td className="px-5 py-3">
                      <AccountStatusBadge status={a.status} />
                    </td>
                    <td className="px-5 py-3 text-right font-medium tabular-nums text-slate-900">{formatMoney(a.balance)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {renaming && (
        <UsernameDialog
          endpoint={`/api/v1/admin/customers/${customer.customer_id}/username`}
          name={fullName}
          currentUserName={customer.login.user_name}
          onClose={() => setRenaming(false)}
          onDone={(message) => {
            setRenaming(false)
            setFlash(message)
            load()
          }}
        />
      )}
    </section>
  )
}

function Card({ title, children, className = '' }) {
  return (
    <div className={`rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 ${className}`}>
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
