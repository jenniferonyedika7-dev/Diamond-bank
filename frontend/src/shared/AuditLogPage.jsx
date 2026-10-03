import { Fragment, useState } from 'react'
import Alert from '../components/Alert.jsx'
import Badge from '../components/Badge.jsx'
import Button from '../components/Button.jsx'
import EmptyState from '../components/EmptyState.jsx'
import FormField from '../components/FormField.jsx'
import Loading from '../components/Loading.jsx'
import PageHeader from '../components/PageHeader.jsx'
import Pagination from '../components/Pagination.jsx'
import { formatDateTime } from '../lib/format.js'
import { useApiList, useDebounced } from '../lib/useApiList.js'

const EMPTY_FILTERS = { action_type: '', table: '', user: '', from: '', to: '' }

/** Read-only audit trail, shared by /staff/audit-log and /admin/audit-log. */
export default function AuditLogPage() {
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState(null)
  const user = useDebounced(filters.user.trim())

  const params = Object.fromEntries(
    Object.entries({ ...filters, user, page }).filter(([, v]) => v !== '' && v !== undefined),
  )
  const { items, pagination, extra, loading, error } = useApiList('/api/v1/staff/audit-log', params)
  const options = extra.filters ?? { action_types: [], tables: [] }

  function update(event) {
    setFilters((prev) => ({ ...prev, [event.target.name]: event.target.value }))
    setPage(1)
  }

  const filtered = Object.values(filters).some((v) => v !== '')

  return (
    <section>
      <PageHeader title="Audit log" description="Every change made through the system, newest first. Read-only." />

      <form className="mb-4 grid gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-2 lg:grid-cols-5" onSubmit={(e) => e.preventDefault()}>
        <FormField id="action_type" label="Action" value={filters.action_type} onChange={update}>
          <option value="">All actions</option>
          {options.action_types.map((a) => (
            <option key={a} value={a}>
              {a}
            </option>
          ))}
        </FormField>
        <FormField id="table" label="Table" value={filters.table} onChange={update}>
          <option value="">All tables</option>
          {options.tables.map((t) => (
            <option key={t} value={t}>
              {t}
            </option>
          ))}
        </FormField>
        <FormField id="user" label="Username" type="search" value={filters.user} onChange={update} />
        <FormField id="from" label="From" type="date" value={filters.from} onChange={update} max={filters.to || undefined} />
        <FormField id="to" label="To" type="date" value={filters.to} onChange={update} min={filters.from || undefined} />
        {filtered && (
          <div className="sm:col-span-2 lg:col-span-5">
            <Button
              variant="secondary"
              className="py-1.5"
              onClick={() => {
                setFilters(EMPTY_FILTERS)
                setPage(1)
              }}
            >
              Clear filters
            </Button>
          </div>
        )}
      </form>

      {loading ? (
        <Loading />
      ) : error ? (
        <Alert>{error}</Alert>
      ) : items.length === 0 ? (
        <EmptyState title={filtered ? 'No entries match these filters.' : 'No audit entries yet.'} />
      ) : (
        <>
          <div className="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                  <th scope="col" className="px-4 py-3">When</th>
                  <th scope="col" className="px-4 py-3">Action</th>
                  <th scope="col" className="px-4 py-3">Record</th>
                  <th scope="col" className="px-4 py-3">By</th>
                  <th scope="col" className="px-4 py-3 text-right">
                    <span className="sr-only">Details</span>
                  </th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((row) => {
                  const open = expanded === row.audit_log_id
                  const detailsId = `audit-details-${row.audit_log_id}`
                  return (
                    <Fragment key={row.audit_log_id}>
                      <tr className={open ? 'bg-navy-50/50' : undefined}>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">{formatDateTime(row.action_timestamp)}</td>
                        <td className="px-4 py-3">
                          <span className="font-mono text-xs font-medium text-slate-900">{row.action_type}</span>
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">
                          {row.table_affected}
                          {row.record_id !== null && <span className="text-slate-500"> #{row.record_id}</span>}
                        </td>
                        <td className="whitespace-nowrap px-4 py-3 text-slate-700">
                          {row.user_name ? (
                            <>
                              {row.user_name} {row.role_name && <Badge>{row.role_name}</Badge>}
                            </>
                          ) : (
                            <span className="text-slate-500">—</span>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right">
                          {row.details !== null && (
                            <button
                              type="button"
                              aria-expanded={open}
                              aria-controls={detailsId}
                              onClick={() => setExpanded(open ? null : row.audit_log_id)}
                              className="rounded text-sm font-medium text-navy-700 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600"
                            >
                              {open ? 'Hide details' : 'Details'}
                            </button>
                          )}
                        </td>
                      </tr>
                      {open && (
                        <tr id={detailsId}>
                          <td colSpan={5} className="bg-slate-50 px-4 py-3">
                            <pre className="max-h-80 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">
                              {JSON.stringify(row.details, null, 2)}
                            </pre>
                            {row.ip_address && <p className="mt-2 text-xs text-slate-500">IP address: {row.ip_address}</p>}
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
          <Pagination pagination={pagination} onPageChange={setPage} />
        </>
      )}
    </section>
  )
}
