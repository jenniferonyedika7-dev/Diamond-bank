import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Pagination from '../../components/Pagination.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import { AccountStatusBadge, CardStatusBadge } from '../../components/StatusBadges.jsx'
import { api } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { useApiList, useDebounced } from '../../lib/useApiList.js'

const TABS = [
  { id: 'requested', label: 'Requests', status: 'REQUESTED', empty: 'No card requests are waiting.' },
  { id: 'active', label: 'Active', status: 'ACTIVE', empty: 'No active cards.' },
  { id: 'blocked', label: 'Blocked', status: 'BLOCKED', empty: 'No blocked cards.' },
  { id: 'rejected', label: 'Rejected', status: 'REJECTED', empty: 'No rejected requests.' },
]

/** Cards on accounts held at the staff member's branch. */
export default function CardsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = TABS.find((t) => t.id === searchParams.get('tab')) ?? TABS[0]
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const { items, pagination, loading, error, reload } = useApiList('/api/v1/staff/cards', {
    status: tab.status,
    search: debouncedSearch || undefined,
    page,
  })
  const [action, setAction] = useState(null) // { type: 'issue'|'reject'|'unblock', card }
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

  const post = (type, body) => api.post(`/api/v1/staff/cards/${action.card.bank_card_id}/${type}`, body)

  return (
    <section>
      <PageHeader title="Cards" description="Issue or reject debit card requests, and unblock cards, for accounts held at your branch." />

      <div role="tablist" aria-label="Cards by status" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={t.id === tab.id}
            aria-controls="cards-panel"
            onClick={() => selectTab(t.id)}
            className={`-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-navy-600 ${
              t.id === tab.id ? 'border-navy-900 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div id="cards-panel" role="tabpanel" aria-labelledby={`tab-${tab.id}`}>
        <Alert tone="success" className="mb-4">
          {flash}
        </Alert>

        <div className="mb-4 max-w-sm">
          <FormField
            id="card-search"
            label="Search by customer name, account number or last 4 digits"
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
          <EmptyState title={debouncedSearch ? `No cards match "${debouncedSearch}".` : tab.empty} />
        ) : (
          <>
            <ul className="space-y-3">
              {items.map((card) => (
                <CardRow key={card.bank_card_id} card={card} onAction={(type) => setAction({ type, card })} />
              ))}
            </ul>
            <Pagination pagination={pagination} onPageChange={setPage} />
          </>
        )}
      </div>

      {action?.type === 'issue' && (
        <ConfirmDialog
          title="Issue this card?"
          confirmLabel="Issue card"
          busyLabel="Issuing…"
          tone="primary"
          onClose={() => setAction(null)}
          onConfirm={async () => done((await post('issue')).data.message)}
        >
          <p>
            A {action.card.card_type.type_name} card for <strong>{action.card.customer.name}</strong> on account{' '}
            <span className="font-mono">{action.card.account_number}</span>. The card number is generated now and the card is
            valid until the end of the month, 3 years from today.
          </p>
        </ConfirmDialog>
      )}
      {action?.type === 'reject' && (
        <ReasonDialog
          title={`Reject card request: ${action.card.customer.name}`}
          intro="The customer sees this reason on their Cards page."
          submitLabel="Reject request"
          busyLabel="Rejecting…"
          onClose={() => setAction(null)}
          onSubmit={async (reason) => done((await post('reject', { reason })).data.message)}
        />
      )}
      {action?.type === 'unblock' && (
        <ConfirmDialog
          title="Unblock this card?"
          confirmLabel="Unblock"
          busyLabel="Unblocking…"
          tone="primary"
          onClose={() => setAction(null)}
          onConfirm={async () => done((await post('unblock')).data.message)}
        >
          <p>
            <strong>{action.card.masked_number}</strong> ({action.card.customer.name}) will work again. Check with the customer that
            the card was found. If they already have a replacement, it can't be unblocked.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function CardRow({ card, onAction }) {
  return (
    <li className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
            <Link
              to={`/staff/customers/${card.customer.customer_id}`}
              className="text-navy-800 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-navy-600"
            >
              {card.customer.name}
            </Link>
            <CardStatusBadge status={card.status} />
          </p>
          <p className="mt-1 text-sm text-slate-600">
            {card.card_type.type_name}
            {card.masked_number && <span className="ml-2 font-mono">{card.masked_number}</span>}
          </p>
          <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <Link to={`/staff/accounts/${card.account_number}`} className="font-mono underline-offset-2 hover:underline">
              {card.account_number}
            </Link>
            {card.account_status !== 'ACTIVE' && <AccountStatusBadge status={card.account_status} />}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            Requested {formatDate(card.requested_at)}
            {card.expiry_date && ` · expires ${formatDate(card.expiry_date)}`}
            {card.rejection_reason && ` · reason: ${card.rejection_reason}`}
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          {card.status === 'REQUESTED' && (
            <>
              <Button onClick={() => onAction('issue')}>
                Issue<span className="sr-only"> card for {card.customer.name}</span>
              </Button>
              <Button variant="secondary" onClick={() => onAction('reject')}>
                Reject<span className="sr-only"> card request from {card.customer.name}</span>
              </Button>
            </>
          )}
          {card.status === 'BLOCKED' && (
            <Button variant="secondary" onClick={() => onAction('unblock')}>
              Unblock<span className="sr-only"> {card.masked_number}</span>
            </Button>
          )}
        </div>
      </div>
    </li>
  )
}
