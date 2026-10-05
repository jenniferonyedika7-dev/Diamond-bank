import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Pagination from '../../components/Pagination.jsx'
import { KycBadge } from '../../components/StatusBadges.jsx'
import { api } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { useApiList, useDebounced } from '../../lib/useApiList.js'

/** Read-only directory of all customers across branches. */
export default function CustomersPage() {
  const [search, setSearch] = useState('')
  const [kycStatus, setKycStatus] = useState('')
  const [branchId, setBranchId] = useState('')
  const [branches, setBranches] = useState([])
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const { items, pagination, loading, error } = useApiList('/api/v1/admin/customers', {
    search: debouncedSearch || undefined,
    kyc_status: kycStatus || undefined,
    branch_id: branchId || undefined,
    page,
  })

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/admin/branches', { params: { per_page: 100 } })
      .then(({ data }) => !ignore && setBranches(data.data.items))
      .catch(() => {}) // the filter just stays empty
    return () => {
      ignore = true
    }
  }, [])

  const filterChange = (setter) => (event) => {
    setter(event.target.value)
    setPage(1)
  }
  const filtered = debouncedSearch || kycStatus || branchId

  return (
    <section>
      <PageHeader title="Customers" description="All customers and their accounts, read-only. Branch staff handle KYC, accounts and blocks." />

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <FormField
          id="customer-search"
          label="Search by name, national ID, phone, email or username"
          type="search"
          className="sm:col-span-3 lg:col-span-1"
          value={search}
          onChange={filterChange(setSearch)}
        />
        <FormField id="kyc_status" label="KYC status" value={kycStatus} onChange={filterChange(setKycStatus)}>
          <option value="">All</option>
          <option value="PENDING">Pending</option>
          <option value="VERIFIED">Verified</option>
          <option value="REJECTED">Rejected</option>
        </FormField>
        <FormField id="branch_id" label="Home branch" value={branchId} onChange={filterChange(setBranchId)}>
          <option value="">All branches</option>
          {branches.map((b) => (
            <option key={b.branch_id} value={b.branch_id}>
              {b.branch_name}
            </option>
          ))}
        </FormField>
      </div>

      {loading ? (
        <Loading />
      ) : error ? (
        <Alert>{error}</Alert>
      ) : items.length === 0 ? (
        <EmptyState title={filtered ? 'No customers match these filters.' : 'No customers yet.'} />
      ) : (
        <>
          <ul className="space-y-3">
            {items.map((c) => (
              <li key={c.customer_id}>
                <Link
                  to={`/admin/customers/${c.customer_id}`}
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
    </section>
  )
}
