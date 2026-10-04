import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import Modal from '../../components/Modal.jsx'
import Pagination from '../../components/Pagination.jsx'
import ReasonDialog from '../../components/ReasonDialog.jsx'
import { useApiList } from '../../lib/useApiList.js'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDateTime, formatPercent } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { AccountStatusBadge } from '../../components/StatusBadges.jsx'
import TransactionList from '../../components/TransactionList.jsx'

export default function AccountDetailPage() {
  const { accountNumber } = useParams()
  const location = useLocation()
  const [account, setAccount] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [dialog, setDialog] = useState(null) // 'deposit' | 'withdraw' | 'freeze' | 'unfreeze'
  const [flash, setFlash] = useState(location.state?.flash ?? '')
  const [page, setPage] = useState(1)
  const history = useApiList(`/api/v1/staff/accounts/${accountNumber}/transactions`, { page })

  const load = useCallback(
    () =>
      api
        .get(`/api/v1/staff/accounts/${accountNumber}`)
        .then(({ data }) => setAccount(data.data))
        .catch((err) => setLoadError(getErrorMessage(err))),
    [accountNumber],
  )

  useEffect(() => {
    load()
  }, [load])

  function done(message) {
    setDialog(null)
    setFlash(message)
    load()
    history.reload()
  }

  if (loadError) return <Alert>{loadError}</Alert>
  if (!account) return <Loading />

  const active = account.status === 'ACTIVE'
  const post = (path, body) => api.post(`/api/v1/staff/accounts/${account.account_number}/${path}`, body)

  return (
    <section className="space-y-6">
      <div>
        <Link to={`/staff/customers/${account.customer.customer_id}`} className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← {account.customer.name}
        </Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="font-mono text-2xl font-semibold text-navy-900">{account.account_number}</h1>
          <AccountStatusBadge status={account.status} />
        </div>
        <p className="mt-1 text-sm text-slate-600">
          {account.account_type.type_name} · {formatPercent(account.account_type.interest_rate)} interest · minimum{' '}
          {formatMoney(account.account_type.minimum_balance)} · {account.branch.branch_name} · opened {formatDateTime(account.opened_date)}
        </p>
      </div>

      <Alert tone="success">{flash}</Alert>

      <div className="flex flex-col gap-4 rounded-2xl bg-navy-900 p-6 text-white sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm text-navy-100">Available balance</p>
          <p className="mt-1 text-4xl font-semibold tabular-nums tracking-tight sm:text-5xl">{formatMoney(account.balance)}</p>
          {account.status === 'FROZEN' && <p className="mt-2 text-sm text-accent-400">Frozen: no deposits, withdrawals or transfers.</p>}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" disabled={!active} onClick={() => setDialog('deposit')}>
            Deposit
          </Button>
          <Button variant="secondary" disabled={!active} onClick={() => setDialog('withdraw')}>
            Withdraw
          </Button>
          {account.status === 'ACTIVE' && (
            <Button variant="secondary" onClick={() => setDialog('freeze')}>
              Freeze
            </Button>
          )}
          {account.status === 'FROZEN' && (
            <Button variant="secondary" onClick={() => setDialog('unfreeze')}>
              Unfreeze
            </Button>
          )}
        </div>
      </div>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-navy-900">Transaction history</h2>
        {history.loading ? (
          <Loading />
        ) : history.error ? (
          <Alert>{history.error}</Alert>
        ) : history.items.length === 0 ? (
          <EmptyState title="No transactions yet." />
        ) : (
          <>
            <TransactionList transactions={history.items} />
            <Pagination pagination={history.pagination} onPageChange={setPage} />
          </>
        )}
      </div>

      {(dialog === 'deposit' || dialog === 'withdraw') && (
        <CashDialog type={dialog} account={account} onClose={() => setDialog(null)} onDone={done} />
      )}
      {dialog === 'freeze' && (
        <ReasonDialog
          title={`Freeze ${account.account_number}`}
          intro="No deposits, withdrawals or transfers are possible until the account is unfrozen."
          submitLabel="Freeze account"
          busyLabel="Freezing…"
          onClose={() => setDialog(null)}
          onSubmit={async (reason) => done((await post('freeze', { reason })).data.message)}
        />
      )}
      {dialog === 'unfreeze' && (
        <ConfirmDialog
          title="Unfreeze account?"
          confirmLabel="Unfreeze"
          busyLabel="Unfreezing…"
          tone="primary"
          onClose={() => setDialog(null)}
          onConfirm={async () => done((await post('unfreeze')).data.message)}
        >
          <p>
            <strong>{account.account_number}</strong> will accept deposits, withdrawals and transfers again.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

const CASH_COPY = {
  deposit: { title: 'Deposit cash', submit: 'Deposit', busy: 'Depositing…', path: 'deposit' },
  withdraw: { title: 'Withdraw cash', submit: 'Withdraw', busy: 'Withdrawing…', path: 'withdraw' },
}

/** Deposit or withdraw at the staff member's branch; shows the new balance before closing. */
function CashDialog({ type, account, onClose, onDone }) {
  const copy = CASH_COPY[type]
  const [form, setForm] = useState({ amount: '', description: '' })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [result, setResult] = useState(null) // { message, balance_after }

  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post(`/api/v1/staff/accounts/${account.account_number}/${copy.path}`, {
        amount: form.amount,
        description: form.description.trim() || null,
      })
      setResult({ message: data.message, balanceAfter: data.data.balance_after })
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
    } finally {
      setSubmitting(false)
    }
  }

  // After success, closing refreshes the page with the result message.
  const close = result ? () => onDone(`${result.message} New balance: ${formatMoney(result.balanceAfter)}.`) : onClose

  return (
    <Modal title={`${copy.title}: ${account.account_number}`} onClose={close}>
      {result ? (
        <div className="space-y-4">
          <Alert tone="success">{result.message}</Alert>
          <div className="rounded-xl bg-navy-50 p-4 text-center">
            <p className="text-sm text-navy-800">New balance</p>
            <p className="mt-1 text-3xl font-semibold tabular-nums text-navy-900">{formatMoney(result.balanceAfter)}</p>
          </div>
          <div className="flex justify-end">
            <Button onClick={close}>Done</Button>
          </div>
        </div>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          <p className="text-sm text-slate-600">
            Current balance: <strong className="tabular-nums">{formatMoney(account.balance)}</strong>
            {type === 'withdraw' && <> · minimum {formatMoney(account.account_type.minimum_balance)}</>}
          </p>
          <Alert>{error}</Alert>
          <FormField
            id="amount"
            label="Amount (GMD)"
            type="number"
            inputMode="decimal"
            step="0.01"
            min="0.01"
            required
            value={form.amount}
            onChange={update}
            error={fieldErrors.amount}
          />
          <FormField id="description" label="Description (optional)" maxLength={255} value={form.description} onChange={update} error={fieldErrors.description} />
          <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" loading={submitting} loadingText={copy.busy}>
              {copy.submit}
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
