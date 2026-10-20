import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import Alert from '../../components/Alert.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Spinner from '../../components/Spinner.jsx'
import { api, getErrorMessage } from '../../lib/api.js'

function StatCard({ label, value, detail, to, small = false }) {
  const body = (
    <>
      <p className="text-sm text-slate-500">{label}</p>
      <p className={`mt-1 font-semibold text-navy-900 ${small ? 'break-words text-xl' : 'text-3xl tabular-nums'}`}>{value}</p>
      {detail && <p className="mt-1 text-xs text-slate-500">{detail}</p>}
    </>
  )
  return to ? (
    <Link to={to} className="card card-interactive block min-w-0 p-5">
      {body}
    </Link>
  ) : (
    <div className="card min-w-0 p-5">{body}</div>
  )
}

export default function OverviewPage() {
  const [overview, setOverview] = useState(null)
  const [bankName, setBankName] = useState('')
  const [error, setError] = useState('')

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/admin/overview')
      .then(({ data }) => !ignore && setOverview(data.data))
      .catch((err) => !ignore && setError(getErrorMessage(err)))
    // Only for the Bank details box; if this read fails the box keeps its plain "Set up".
    api
      .get('/api/v1/admin/bank')
      .then(({ data }) => !ignore && setBankName(data.data?.bank_name ?? ''))
      .catch(() => {})
    return () => {
      ignore = true
    }
  }, [])

  if (error) return <Alert>{error}</Alert>
  if (!overview) {
    return (
      <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
        <Spinner /> Loading…
      </div>
    )
  }

  const { customers } = overview

  return (
    <section>
      <PageHeader title="Overview" description="The state of the bank at a glance." />

      <div className="mb-6 space-y-3">
        {!overview.bank_configured && (
          <div className="flex flex-col gap-4 rounded-xl border-l-4 border-accent-500 bg-navy-900 p-5 text-white sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="text-lg font-semibold">Set up your bank first</p>
              <p className="mt-1 text-sm text-navy-100">
                Add the bank's name, SWIFT code and founding date. Branches, and then customers and staff, depend on it.
              </p>
            </div>
            <Link
              to="/admin/bank"
              className="inline-flex shrink-0 items-center justify-center rounded-lg bg-accent-400 px-4 py-2.5 text-sm font-semibold text-navy-900 hover:bg-accent-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
            >
              Go to Bank settings
            </Link>
          </div>
        )}

        {overview.pending_access_requests > 0 && (
          <Link
            to="/admin/access-requests"
            className="flex items-center justify-between gap-4 rounded-xl bg-amber-50 p-4 text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
          >
            <span className="font-medium">
              {overview.pending_access_requests} access {overview.pending_access_requests === 1 ? 'request' : 'requests'} awaiting your decision
            </span>
            <span className="text-sm font-semibold">Review →</span>
          </Link>
        )}

        {overview.pending_staff > 0 && (
          <Link
            to="/admin/staff?tab=pending"
            className="flex items-center justify-between gap-4 rounded-xl bg-amber-50 p-4 text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-600"
          >
            <span className="font-medium">
              {overview.pending_staff} staff {overview.pending_staff === 1 ? 'member' : 'members'} awaiting approval
            </span>
            <span className="text-sm font-semibold">Review →</span>
          </Link>
        )}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard label="Pending staff" value={overview.pending_staff} to="/admin/staff?tab=pending" />
        <StatCard label="Active staff" value={overview.active_staff} detail={`${overview.blocked_staff} blocked`} to="/admin/staff?tab=active" />
        <StatCard label="Access requests" value={overview.pending_access_requests} detail="pending" to="/admin/access-requests" />
        <StatCard label="Branches" value={overview.branches} to="/admin/branches" />
        <StatCard
          label="Customers"
          value={customers.total}
          detail={`KYC: ${customers.PENDING} pending · ${customers.VERIFIED} verified · ${customers.REJECTED} rejected`}
          to="/admin/customers"
        />
        <StatCard label="Accounts" value={overview.accounts} />
        <StatCard
          label="Bank details"
          value={overview.bank_configured ? bankName || 'Set up' : 'Missing'}
          small={overview.bank_configured && bankName !== ''}
          to="/admin/bank"
        />
      </div>
    </section>
  )
}
