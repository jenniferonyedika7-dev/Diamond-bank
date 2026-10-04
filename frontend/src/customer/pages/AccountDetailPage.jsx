import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import Pagination from '../../components/Pagination.jsx'
import { AccountStatusBadge } from '../../components/StatusBadges.jsx'
import TransactionList from '../../components/TransactionList.jsx'
import { api, getErrorMessage } from '../../lib/api.js'
import { formatPercent } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { useApiList } from '../../lib/useApiList.js'

const TYPES = [
  ['DEPOSIT', 'Deposits'],
  ['WITHDRAWAL', 'Withdrawals'],
  ['TRANSFER_IN', 'Transfers in'],
  ['TRANSFER_OUT', 'Transfers out'],
  ['LOAN_DISBURSEMENT', 'Loan disbursements'],
  ['LOAN_PAYMENT', 'Loan payments'],
]
const EMPTY_FILTERS = { type: '', from: '', to: '' }

export default function AccountDetailPage() {
  const { accountNumber } = useParams()
  const [account, setAccount] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [filters, setFilters] = useState(EMPTY_FILTERS)
  const [page, setPage] = useState(1)

  const params = Object.fromEntries(Object.entries({ ...filters, page }).filter(([, v]) => v !== ''))
  const history = useApiList(`/api/v1/customer/accounts/${accountNumber}/transactions`, params)

  useEffect(() => {
    let ignore = false
    api
      .get(`/api/v1/customer/accounts/${accountNumber}`)
      .then(({ data }) => !ignore && setAccount(data.data))
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [accountNumber])

  function update(event) {
    setFilters((prev) => ({ ...prev, [event.target.name]: event.target.value }))
    setPage(1)
  }

  if (loadError) return <Alert>{loadError}</Alert>
  if (!account) return <Loading />

  const filtered = Object.values(filters).some((v) => v !== '')

  return (
    <section className="space-y-6">
      <div>
        <Link to="/customer/accounts" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Accounts
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-2xl font-semibold text-navy-900">{account.account_type.type_name}</h1>
          <AccountStatusBadge status={account.status} />
        </div>
        <p className="mt-1 font-mono text-sm text-slate-600">{account.account_number}</p>
      </div>

      <div className="flex flex-col gap-4 rounded-2xl bg-navy-900 p-6 text-white sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm text-navy-100">Balance</p>
          <p className="mt-1 text-4xl font-semibold tabular-nums tracking-tight sm:text-5xl">{formatMoney(account.balance)}</p>
          <p className="mt-2 text-sm text-navy-100">
            Minimum balance {formatMoney(account.account_type.minimum_balance)} · {formatPercent(account.account_type.interest_rate)} interest ·{' '}
            {account.branch_name}
          </p>
          {account.status === 'FROZEN' && (
            <p className="mt-2 text-sm text-accent-400">This account is frozen. Please contact your branch.</p>
          )}
        </div>
        {account.status === 'ACTIVE' && (
          <Link
            to={`/customer/transfer?from=${account.account_number}`}
            className="inline-flex items-center justify-center rounded-lg bg-accent-400 px-4 py-2.5 text-sm font-semibold text-navy-900 hover:bg-accent-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
          >
            Transfer from this account
          </Link>
        )}
      </div>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-navy-900">Transaction history</h2>
        <form
          className="mb-4 grid gap-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:grid-cols-3"
          onSubmit={(e) => e.preventDefault()}
        >
          <FormField id="type" label="Type" value={filters.type} onChange={update}>
            <option value="">All types</option>
            {TYPES.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </FormField>
          <FormField id="from" label="From" type="date" value={filters.from} onChange={update} max={filters.to || undefined} />
          <FormField id="to" label="To" type="date" value={filters.to} onChange={update} min={filters.from || undefined} />
          {filtered && (
            <div className="sm:col-span-3">
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

        {history.loading ? (
          <Loading />
        ) : history.error ? (
          <Alert>{history.error}</Alert>
        ) : history.items.length === 0 ? (
          <EmptyState title={filtered ? 'No transactions match these filters.' : 'No transactions yet.'} />
        ) : (
          <>
            <TransactionList transactions={history.items} />
            <Pagination pagination={history.pagination} onPageChange={setPage} />
          </>
        )}
      </div>
    </section>
  )
}
