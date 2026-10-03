import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Pagination from '../../components/Pagination.jsx'
import { useApiList, useDebounced } from '../../lib/useApiList.js'
import { formatDate } from '../../lib/format.js'
import { KycBadge } from '../badges.jsx'

const TABS = [
  { id: 'pending', label: 'Pending KYC', status: 'PENDING', empty: 'No customers are waiting for KYC.' },
  { id: 'verified', label: 'Verified', status: 'VERIFIED', empty: 'No verified customers yet.' },
  { id: 'rejected', label: 'Rejected', status: 'REJECTED', empty: 'No rejected customers.' },
  { id: 'all', label: 'All', status: undefined, empty: 'No customers yet.' },
]

export default function CustomersPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.find((t) => t.id === searchParams.get('tab')) ?? TABS[0]
  const [search, setSearch] = useState(searchParams.get('search') ?? '')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const { items, pagination, loading, error } = useApiList('/api/v1/staff/customers', {
    kyc_status: tab.status,
    search: debouncedSearch || undefined,
    page,
  })

  function selectTab(id) {
    setSearchParams(search ? { tab: id, search } : { tab: id })
    setPage(1)
  }

  return (
    <section>
      <PageHeader title="Customers" description="Check identity documents, keep details up to date and open accounts." />

      <div role="tablist" aria-label="Customers by KYC status" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={t.id === tab.id}
            aria-controls="customers-panel"
            onClick={() => selectTab(t.id)}
            className={`-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-navy-600 ${
              t.id === tab.id ? 'border-navy-900 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div id="customers-panel" role="tabpanel" aria-labelledby={`tab-${tab.id}`}>
        <div className="mb-4 max-w-sm">
          <FormField
            id="customer-search"
            label="Search by name, national ID, phone, email or username"
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>

        {loading ? (
          <Loading />
        ) : error ? (
          <Alert>{error}</Alert>
        ) : items.length === 0 ? (
          <EmptyState title={debouncedSearch ? `No customers match "${debouncedSearch}".` : tab.empty} />
        ) : (
          <>
            <ul className="space-y-3">
              {items.map((c) => (
                <li key={c.customer_id}>
                  <Link
                    to={`/staff/customers/${c.customer_id}`}
                    className="block rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 hover:ring-navy-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600 sm:p-5"
                  >
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">
                        {c.first_name} {c.last_name}
                      </span>
                      {c.user_name && <span className="text-sm text-slate-500">@{c.user_name}</span>}
                      <KycBadge status={c.kyc_status} />
                      {c.user_status === 'BLOCKED' && <Badge tone="danger">Login blocked</Badge>}
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                      ID {c.national_id} · {c.phone} · {c.email}
                    </p>
                    <p className="mt-1 text-xs text-slate-500">
                      {c.branch.branch_name} · registered {formatDate(c.registration_date)} · {c.accounts_count}{' '}
                      {c.accounts_count === 1 ? 'account' : 'accounts'}
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
