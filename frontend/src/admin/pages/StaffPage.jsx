import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Modal from '../../components/Modal.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import Pagination from '../../components/Pagination.jsx'
import Spinner from '../../components/Spinner.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { useApiList, useDebounced } from '../../lib/useApiList.js'
import UsernameDialog from '../UsernameDialog.jsx'

const REASON_COPY = {
  reject: {
    title: 'Reject registration',
    intro: "They won't be able to sign in. This can't be undone; they would need to register again.",
    submitLabel: 'Reject registration',
    busyLabel: 'Rejecting…',
  },
  block: {
    title: 'Block staff member',
    intro: 'They are signed out straight away and cannot sign in until unblocked.',
    submitLabel: 'Block',
    busyLabel: 'Blocking…',
  },
}

const TABS = [
  { id: 'pending', label: 'Pending', status: 'PENDING', empty: 'No registrations are waiting for approval.' },
  { id: 'active', label: 'Active', status: 'ACTIVE', empty: 'No active staff yet. Approve a pending registration first.' },
  { id: 'blocked', label: 'Blocked', status: 'BLOCKED', empty: 'No blocked or rejected staff.' },
]

export default function StaffPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.find((t) => t.id === searchParams.get('tab')) ?? TABS[0]
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const { items, pagination, loading, error, reload } = useApiList('/api/v1/admin/staff', {
    status: tab.status,
    page,
    search: debouncedSearch || undefined,
  })

  const [action, setAction] = useState(null) // { type: 'approve'|'reject'|'block'|'unblock'|'username', user }
  const [flash, setFlash] = useState('')

  function selectTab(id) {
    setSearchParams({ tab: id })
    setPage(1)
    setFlash('')
  }

  function done(message) {
    setAction(null)
    setFlash(message)
    reload()
  }

  return (
    <section>
      <PageHeader title="Staff" description="Approve new staff registrations, block or unblock staff accounts, and change usernames." />

      <div role="tablist" aria-label="Staff by status" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={t.id === tab.id}
            aria-controls="staff-panel"
            onClick={() => selectTab(t.id)}
            className={`-mb-px border-b-2 px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-navy-600 ${
              t.id === tab.id ? 'border-navy-900 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div id="staff-panel" role="tabpanel" aria-labelledby={`tab-${tab.id}`}>
        <Alert tone="success" className="mb-4">
          {flash}
        </Alert>

        <div className="mb-4 max-w-sm">
          <FormField
            id="staff-search"
            label="Search by name or username"
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>

        {loading ? (
          <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
            <Spinner /> Loading…
          </div>
        ) : error ? (
          <Alert>{error}</Alert>
        ) : items.length === 0 ? (
          <EmptyState title={debouncedSearch ? `No staff match "${debouncedSearch}".` : tab.empty} />
        ) : (
          <>
            <ul className="space-y-3">
              {items.map((user) => (
                <StaffCard key={user.user_id} user={user} tab={tab.id} onAction={(type) => setAction({ type, user })} />
              ))}
            </ul>
            <Pagination pagination={pagination} onPageChange={setPage} />
          </>
        )}
      </div>

      {action?.type === 'approve' && <ApproveDialog user={action.user} onClose={() => setAction(null)} onDone={done} />}
      {(action?.type === 'reject' || action?.type === 'block') && (
        <ReasonDialog
          {...REASON_COPY[action.type]}
          title={`${REASON_COPY[action.type].title}: ${action.user.full_name}`}
          onClose={() => setAction(null)}
          onSubmit={async (reason) => {
            const { data } = await api.post(`/api/v1/admin/staff/${action.user.user_id}/${action.type}`, { reason })
            done(data.message)
          }}
        />
      )}
      {action?.type === 'username' && (
        <UsernameDialog
          endpoint={`/api/v1/admin/staff/${action.user.user_id}/username`}
          name={action.user.full_name}
          currentUserName={action.user.user_name}
          onClose={() => setAction(null)}
          onDone={done}
        />
      )}
      {action?.type === 'unblock' && (
        <ConfirmDialog
          title="Unblock staff member?"
          confirmLabel="Unblock"
          busyLabel="Unblocking…"
          tone="primary"
          onClose={() => setAction(null)}
          onConfirm={async () => {
            const { data } = await api.post(`/api/v1/admin/staff/${action.user.user_id}/unblock`)
            done(data.message)
          }}
        >
          <p>
            <strong>{action.user.full_name}</strong> ({action.user.user_name}) will be able to sign in again.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function StaffCard({ user, tab, onAction }) {
  // A blocked user with no branch never got approved: their registration was rejected.
  const rejected = user.status === 'BLOCKED' && !user.branch

  return (
    <li className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
            {user.full_name}
            <span className="text-sm font-normal text-slate-500">@{user.user_name}</span>
            {rejected && <Badge tone="danger">Rejected</Badge>}
            {user.status === 'BLOCKED' && !rejected && <Badge tone="warning">Blocked</Badge>}
          </p>
          <dl className="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
            <Detail label="Position" value={user.position} />
            <Detail label="National ID" value={user.national_id} />
            <Detail label="Email" value={user.email} />
            <Detail label="Phone" value={user.phone} />
            {user.branch && <Detail label="Branch" value={user.branch.branch_name} />}
            {user.branch && <Detail label="Department" value={user.department?.department_name ?? '—'} />}
            <Detail label="Registered" value={formatDate(user.created_at)} />
          </dl>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          {tab === 'pending' && (
            <>
              <Button onClick={() => onAction('approve')}>
                Approve<span className="sr-only"> {user.full_name}</span>
              </Button>
              <Button variant="secondary" onClick={() => onAction('reject')}>
                Reject<span className="sr-only"> {user.full_name}</span>
              </Button>
            </>
          )}
          {tab === 'active' && (
            <Button variant="secondary" onClick={() => onAction('block')}>
              Block<span className="sr-only"> {user.full_name}</span>
            </Button>
          )}
          {tab === 'blocked' && !rejected && (
            <Button variant="secondary" onClick={() => onAction('unblock')}>
              Unblock<span className="sr-only"> {user.full_name}</span>
            </Button>
          )}
          <Button variant="secondary" onClick={() => onAction('username')}>
            Change username<span className="sr-only"> for {user.full_name}</span>
          </Button>
        </div>
      </div>
    </li>
  )
}

function Detail({ label, value }) {
  return (
    <div className="flex gap-2">
      <dt className="text-slate-500">{label}:</dt>
      <dd className="min-w-0 break-words text-slate-800">{value}</dd>
    </div>
  )
}

function ApproveDialog({ user, onClose, onDone }) {
  const [options, setOptions] = useState(null) // { branches, departments }
  const [loadError, setLoadError] = useState('')
  const [form, setForm] = useState({ branch_id: '', department_id: '' })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let ignore = false
    Promise.all([
      api.get('/api/v1/admin/branches', { params: { per_page: 100 } }),
      api.get('/api/v1/admin/departments'),
    ])
      .then(([branches, departments]) => {
        if (!ignore) setOptions({ branches: branches.data.data.items, departments: departments.data.data })
      })
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [])

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post(`/api/v1/admin/staff/${user.user_id}/approve`, {
        branch_id: form.branch_id === '' ? null : Number(form.branch_id),
        department_id: form.department_id === '' ? null : Number(form.department_id),
      })
      onDone(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))

  return (
    <Modal title={`Approve ${user.full_name}`} onClose={onClose}>
      {loadError ? (
        <Alert>{loadError}</Alert>
      ) : !options ? (
        <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
          <Spinner /> Loading branches…
        </div>
      ) : options.branches.length === 0 ? (
        <div className="space-y-4">
          <Alert tone="info">Add a branch first: staff must be assigned to a branch when they are approved.</Alert>
          <Link to="/admin/branches" className="inline-block text-sm font-semibold text-navy-700 underline">
            Go to Branches
          </Link>
        </div>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          <p className="text-sm text-slate-600">
            {user.user_name} will be able to sign in as staff from today.
          </p>
          <Alert>{error}</Alert>
          <FormField id="branch_id" label="Branch" required value={form.branch_id} onChange={update} error={fieldErrors.branch_id}>
            <option value="">Select a branch…</option>
            {options.branches.map((b) => (
              <option key={b.branch_id} value={b.branch_id}>
                {b.branch_name} ({b.branch_code})
              </option>
            ))}
          </FormField>
          <FormField
            id="department_id"
            label="Department (optional)"
            value={form.department_id}
            onChange={update}
            error={fieldErrors.department_id}
          >
            <option value="">No department</option>
            {options.departments.map((d) => (
              <option key={d.department_id} value={d.department_id}>
                {d.department_name}
              </option>
            ))}
          </FormField>
          <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={submitting} loadingText="Approving…">
              Approve
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
