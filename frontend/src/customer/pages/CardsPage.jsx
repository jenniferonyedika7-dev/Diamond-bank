import { useEffect, useState } from 'react'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import ConfirmDialog from '../../components/ConfirmDialog.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { CardStatusBadge } from '../../components/StatusBadges.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { useApiList } from '../../lib/useApiList.js'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

// An account has at most one card that is requested or active; a blocked card can be replaced.
const LIVE = ['REQUESTED', 'ACTIVE']

export default function CardsPage() {
  const { overview } = useCustomerData()
  const { customer, accounts } = overview
  const cards = useApiList('/api/v1/customer/cards')
  const [blocking, setBlocking] = useState(null)
  const [flash, setFlash] = useState('')

  function done(message) {
    setBlocking(null)
    setFlash(message)
    cards.reload()
  }

  return (
    <section className="space-y-6">
      <PageHeader title="Cards" description="Request a debit card for one of your accounts, and block a card if it is lost or stolen." />
      <KycNotice customer={customer} accounts={accounts} />
      <Alert tone="success">{flash}</Alert>

      {cards.loading ? (
        <Loading />
      ) : cards.error ? (
        <Alert>{cards.error}</Alert>
      ) : (
        <>
          {cards.items.length === 0 ? (
            <EmptyState title="You have no cards yet." />
          ) : (
            <ul className="grid gap-4 sm:grid-cols-2">
              {cards.items.map((card) => (
                <CardTile key={card.bank_card_id} card={card} onBlock={() => setBlocking(card)} />
              ))}
            </ul>
          )}

          {canBank(customer, accounts) && (
            <RequestCardForm
              accounts={accounts}
              cards={cards.items}
              onDone={(message) => {
                setFlash(message)
                cards.reload()
              }}
            />
          )}
        </>
      )}

      {blocking && (
        <ConfirmDialog
          title="Block this card?"
          confirmLabel="Block card"
          busyLabel="Blocking…"
          onClose={() => setBlocking(null)}
          onConfirm={async () => done((await api.post(`/api/v1/customer/cards/${blocking.bank_card_id}/block`)).data.message)}
        >
          <p>
            <strong>{blocking.masked_number}</strong> stops working straight away. You can then request a replacement. Only your branch can
            unblock it.
          </p>
        </ConfirmDialog>
      )}
    </section>
  )
}

function CardTile({ card, onBlock }) {
  return (
    <li className="flex flex-col rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <div className="flex items-center justify-between gap-2">
        <span className="text-sm font-medium text-slate-700">{card.card_type.type_name}</span>
        <CardStatusBadge status={card.status} />
      </div>
      <p className="mt-3 font-mono text-lg tracking-wider text-navy-900">
        {card.masked_number ?? <span className="font-sans text-base tracking-normal text-slate-500">Number given on issue</span>}
      </p>
      <dl className="mt-2 space-y-0.5 text-sm text-slate-600">
        <div>
          <dt className="inline">Account: </dt>
          <dd className="inline font-mono">{card.account_number}</dd>
        </div>
        {card.expiry_date && (
          <div>
            <dt className="inline">Expires: </dt>
            <dd className="inline">{formatDate(card.expiry_date)}</dd>
          </div>
        )}
        <div>
          <dt className="inline">Daily limit: </dt>
          <dd className="inline">{formatMoney(card.card_type.daily_limit)}</dd>
        </div>
        {card.status === 'REJECTED' && card.rejection_reason && (
          <div>
            <dt className="inline">Reason: </dt>
            <dd className="inline">{card.rejection_reason}</dd>
          </div>
        )}
      </dl>
      {card.status === 'REQUESTED' && (
        <p className="mt-2 text-sm text-slate-600">Requested {formatDate(card.requested_at)}. Your branch will review it.</p>
      )}
      {card.status === 'ACTIVE' && (
        <div className="mt-4">
          <Button variant="secondary" className="py-1.5" onClick={onBlock}>
            Block card<span className="sr-only"> {card.masked_number}</span>
          </Button>
        </div>
      )}
    </li>
  )
}

function RequestCardForm({ accounts, cards, onDone }) {
  const [types, setTypes] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [form, setForm] = useState({ account_number: '', card_type_id: '' })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/customer/card-types')
      .then(({ data }) => !ignore && setTypes(data.data))
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [])

  const hasLiveCard = (accountNumber) => cards.some((c) => c.account_number === accountNumber && LIVE.includes(c.status))
  const eligible = accounts.filter((a) => a.status === 'ACTIVE' && !hasLiveCard(a.account_number))
  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post('/api/v1/customer/cards', {
        account_number: form.account_number,
        card_type_id: form.card_type_id === '' ? null : Number(form.card_type_id),
      })
      setForm({ account_number: '', card_type_id: '' })
      onDone(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
    }
    setSubmitting(false)
  }

  return (
    <div className="max-w-xl rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
      <h2 className="mb-1 text-lg font-semibold text-navy-900">Request a debit card</h2>
      <p className="mb-4 text-sm text-slate-600">One card per account. Frozen or closed accounts can't have a card.</p>
      {loadError ? (
        <Alert>{loadError}</Alert>
      ) : !types ? (
        <Loading label="Loading card types…" />
      ) : types.length === 0 ? (
        <Alert tone="info">Cards are not available yet. Please check again later.</Alert>
      ) : eligible.length === 0 ? (
        <Alert tone="info">Each of your active accounts already has a card or a pending request.</Alert>
      ) : (
        <form onSubmit={handleSubmit} noValidate className="space-y-4">
          <Alert>{error}</Alert>
          <FormField id="account_number" label="Account" required value={form.account_number} onChange={update} error={fieldErrors.account_number}>
            <option value="">Select an account…</option>
            {eligible.map((a) => (
              <option key={a.account_number} value={a.account_number}>
                {a.type_name} · {a.account_number}
              </option>
            ))}
          </FormField>
          <FormField id="card_type_id" label="Card type" required value={form.card_type_id} onChange={update} error={fieldErrors.card_type_id}>
            <option value="">Select a card type…</option>
            {types.map((t) => (
              <option key={t.card_type_id} value={t.card_type_id}>
                {t.type_name}: daily limit {formatMoney(t.daily_limit)}
              </option>
            ))}
          </FormField>
          <Button type="submit" loading={submitting} loadingText="Requesting…" disabled={!form.account_number || !form.card_type_id}>
            Request card
          </Button>
        </form>
      )}
    </div>
  )
}
