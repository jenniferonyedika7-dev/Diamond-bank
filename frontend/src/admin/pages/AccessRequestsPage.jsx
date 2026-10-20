import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Pagination from '../../components/Pagination.jsx'
import { formatDateTime } from '../../lib/format.js'
import { useApiList } from '../../lib/useApiList.js'
import { CHANNEL_LABELS, RECOVERY_LABELS, STATUS_TONES, TYPE_LABELS } from '../../shared/access/labels.js'

const TABS = [
  { id: 'pending', label: 'Pending', status: 'PENDING', empty: 'No access requests are waiting for you.' },
  { id: 'approved', label: 'Approved', status: 'APPROVED', empty: 'No approved requests yet.' },
  { id: 'rejected', label: 'Rejected', status: 'REJECTED', empty: 'No rejected requests.' },
]

/** Customers locked out of their login: requests raised by staff at the home branch, for an administrator to decide. */
export default function AccessRequestsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.find((t) => t.id === searchParams.get('tab')) ?? TABS[0]
  const [page, setPage] = useState(1)
  const { items, pagination, extra, loading, error } = useApiList('/api/v1/admin/access-requests', { status: tab.status, page })

  return (
    <section>
      <PageHeader
        title="Access requests"
        description={
          extra.pending_count === undefined
            ? 'Customers locked out of their online login. Staff at the home branch checked their identity; you approve or reject.'
            : `${extra.pending_count} pending. Customers locked out of their online login; staff at the home branch checked their identity.`
        }
      />

      <div role="tablist" aria-label="Access requests by status" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={t.id === tab.id}
            aria-controls="requests-panel"
            onClick={() => {
              setSearchParams({ tab: t.id })
              setPage(1)
            }}
            className={`-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-navy-600 ${
              t.id === tab.id ? 'border-navy-900 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'
            }`}
          >
            {t.label}
            {t.id === 'pending' && extra.pending_count > 0 && <span className="ml-1.5 text-xs text-slate-500">({extra.pending_count})</span>}
          </button>
        ))}
      </div>

      <div id="requests-panel" role="tabpanel" aria-labelledby={`tab-${tab.id}`}>
        {loading ? (
          <Loading />
        ) : error ? (
          <Alert>{error}</Alert>
        ) : items.length === 0 ? (
          <EmptyState title={tab.empty} />
        ) : (
          <>
            <ul className="space-y-3">
              {items.map((r) => (
                <li key={r.access_request_id}>
                  <Link
                    to={`/admin/access-requests/${r.access_request_id}`}
                    className="card card-interactive block p-4 sm:p-5"
                  >
                    <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
                      {r.customer_name}
                      <span className="text-sm font-normal text-slate-500">@{r.user_name}</span>
                      <Badge tone={STATUS_TONES[r.status]}>{r.status.charAt(0) + r.status.slice(1).toLowerCase()}</Badge>
                    </p>
                    <p className="mt-1 text-sm text-slate-700">
                      {TYPE_LABELS[r.request_type]} · {RECOVERY_LABELS[r.reason]}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {CHANNEL_LABELS[r.contact_channel]} at {r.branch_name}, raised by {r.requested_by_name ?? 'unknown'} on{' '}
                      {formatDateTime(r.requested_at)}
                      {r.reviewed_at && ` · decided by ${r.reviewed_by_name ?? 'unknown'} on ${formatDateTime(r.reviewed_at)}`}
                    </p>
                  </Link>
                </li>
              ))}
            </ul>
            <Pagination pagination={pagination} onPageChange={setPage} />
          </>
        )}
      </div>
    </section>
  )
}
