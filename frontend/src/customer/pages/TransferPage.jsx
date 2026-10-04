import { useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import FormField from '../../components/FormField.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatMoney } from '../../lib/money.js'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

const STEPS = { details: 'Step 1 of 3: Transfer details', review: 'Step 2 of 3: Review and confirm', result: 'Step 3 of 3: Result' }
const AMOUNT = /^\d+(\.\d{1,2})?$/

/** How much can leave the account without going below its minimum balance. */
function available(account) {
  return Math.max(Number(account.balance) - Number(account.minimum_balance), 0)
}

export default function TransferPage() {
  const { overview, reload } = useCustomerData()
  const { customer, accounts } = overview
  const [searchParams] = useSearchParams()
  const active = accounts.filter((a) => a.status === 'ACTIVE')
  const preselected = active.find((a) => a.account_number === searchParams.get('from'))?.account_number

  const [step, setStep] = useState('details')
  const [form, setForm] = useState({
    from_account_number: preselected ?? active[0]?.account_number ?? '',
    to_account_number: '',
    amount: '',
    description: '',
  })
  const [recipient, setRecipient] = useState(null) // { account_number, recipient }
  const [password, setPassword] = useState('')
  const [pending, setPending] = useState(false)
  const submittingRef = useRef(false) // blocks a second submit before React re-renders the disabled button
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [result, setResult] = useState(null) // { ok: true, data, message } | { ok: false, message }

  if (!canBank(customer, accounts)) {
    return (
      <section className="space-y-6">
        <PageHeader title="Transfer money" />
        <KycNotice customer={customer} accounts={accounts} />
      </section>
    )
  }

  const source = accounts.find((a) => a.account_number === form.from_account_number)
  const update = (event) => setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))

  function reset(keepForm) {
    setStep('details')
    setPassword('')
    setError('')
    setFieldErrors({})
    setResult(null)
    setRecipient(null)
    if (!keepForm) setForm((prev) => ({ ...prev, to_account_number: '', amount: '', description: '' }))
  }

  // Step 1 -> 2: check the obvious locally, then look the recipient up (masked name only).
  async function handleContinue(event) {
    event.preventDefault()
    if (submittingRef.current) return
    const to = form.to_account_number.trim().toUpperCase()
    const errors = {}
    if (!to) errors.to_account_number = 'Enter the recipient’s account number.'
    else if (to === form.from_account_number) errors.to_account_number = "You can't transfer to the same account."
    if (!AMOUNT.test(form.amount.trim()) || Number(form.amount) <= 0) {
      errors.amount = 'Enter an amount greater than zero, with at most 2 decimal places.'
    }
    setError('')
    setFieldErrors(errors)
    if (Object.keys(errors).length > 0) return

    submittingRef.current = true
    setPending(true)
    try {
      const { data } = await api.post('/api/v1/customer/transfers/lookup', { to_account_number: to })
      setForm((prev) => ({ ...prev, to_account_number: to, amount: prev.amount.trim() }))
      setRecipient(data.data)
      setStep('review')
    } catch (err) {
      if (err.response?.status === 422) {
        setFieldErrors({ to_account_number: getFieldErrors(err).to_account_number ?? getErrorMessage(err) })
      } else {
        setError(getErrorMessage(err))
      }
    } finally {
      submittingRef.current = false
      setPending(false)
    }
  }

  // Step 2 -> 3: send with the password. Wrong password stays here; the procedure's refusals go to step 3.
  async function handleSend(event) {
    event.preventDefault()
    if (submittingRef.current) return
    submittingRef.current = true
    setPending(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post('/api/v1/customer/transfers', { ...form, description: form.description.trim() || null, password })
      setResult({ ok: true, data: data.data, message: data.message })
      setStep('result')
      reload()
    } catch (err) {
      const errors = getFieldErrors(err)
      if (errors.password) {
        setFieldErrors({ password: errors.password })
      } else if (err.response?.status === 422 && Object.keys(errors).length > 0) {
        // A details problem (amount, accounts): back to step 1 with the messages under the fields.
        setFieldErrors(errors)
        setStep('details')
      } else if (err.response?.status === 422) {
        setResult({ ok: false, message: getErrorMessage(err) })
        setStep('result')
      } else {
        setError(getErrorMessage(err))
      }
    } finally {
      setPassword('')
      submittingRef.current = false
      setPending(false)
    }
  }

  return (
    <section className="max-w-xl space-y-6">
      <PageHeader title="Transfer money" />
      <p className="text-sm font-medium text-slate-600" aria-live="polite">
        {STEPS[step]}
      </p>

      {step === 'details' && (
        <form onSubmit={handleContinue} noValidate className="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <Alert>{error}</Alert>
          {active.length === 0 ? (
            <Alert tone="info">None of your accounts can send money right now. Please contact your branch.</Alert>
          ) : (
            <>
              <FormField
                id="from_account_number"
                label="From account"
                value={form.from_account_number}
                onChange={update}
                error={fieldErrors.from_account_number}
                hint={source ? `You can send up to ${formatMoney(available(source))} (keeps the ${formatMoney(source.minimum_balance)} minimum).` : undefined}
              >
                {accounts.map((a) => (
                  <option key={a.account_number} value={a.account_number} disabled={a.status !== 'ACTIVE'}>
                    {a.type_name} · {a.account_number} · {formatMoney(a.balance)}
                    {a.status !== 'ACTIVE' && ` (${a.status.toLowerCase()})`}
                  </option>
                ))}
              </FormField>
              <FormField
                id="to_account_number"
                label="Recipient account number"
                required
                maxLength={20}
                autoComplete="off"
                className="[&_input]:font-mono [&_input]:uppercase"
                value={form.to_account_number}
                onChange={update}
                error={fieldErrors.to_account_number}
                hint="For example DB0010000002."
              />
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
              <FormField
                id="description"
                label="Description (optional)"
                maxLength={255}
                value={form.description}
                onChange={update}
                error={fieldErrors.description}
              />
              <Button type="submit" className="w-full" loading={pending} loadingText="Checking recipient…">
                Continue
              </Button>
            </>
          )}
        </form>
      )}

      {step === 'review' && recipient && (
        <form onSubmit={handleSend} noValidate className="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          <Alert>{error}</Alert>
          <p className="text-lg text-slate-900">
            Send <strong className="tabular-nums">{formatMoney(form.amount)}</strong> to <strong>{recipient.recipient}</strong>{' '}
            <span className="font-mono text-base text-slate-600">({recipient.account_number})</span>
          </p>
          <dl className="space-y-1 text-sm">
            <div className="flex gap-2">
              <dt className="text-slate-500">From:</dt>
              <dd className="text-slate-900">
                {source?.type_name} · <span className="font-mono">{form.from_account_number}</span>
              </dd>
            </div>
            {form.description.trim() && (
              <div className="flex gap-2">
                <dt className="text-slate-500">Description:</dt>
                <dd className="break-words text-slate-900">{form.description.trim()}</dd>
              </div>
            )}
          </dl>
          <FormField
            id="password"
            label="Your password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            error={fieldErrors.password}
            hint="We ask for your password to confirm it's you."
          />
          <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" disabled={pending} onClick={() => reset(true)}>
              Back
            </Button>
            <Button type="submit" loading={pending} loadingText="Sending…" disabled={password === ''}>
              Send money
            </Button>
          </div>
        </form>
      )}

      {step === 'result' && result && (
        <div className="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
          {result.ok ? (
            <>
              <Alert tone="success">
                {result.message} You sent {formatMoney(result.data.amount)} to {result.data.recipient} ({result.data.to_account_number}).
              </Alert>
              <div className="rounded-xl bg-navy-50 p-4 text-center">
                <p className="text-sm text-navy-800">New balance of {result.data.from_account_number}</p>
                <p className="mt-1 text-3xl font-semibold tabular-nums text-navy-900">{formatMoney(result.data.balance_after)}</p>
              </div>
              <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <Link
                  to={`/customer/accounts/${result.data.from_account_number}`}
                  className="inline-flex items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-navy-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
                >
                  View account
                </Link>
                <Button onClick={() => reset(false)}>Make another transfer</Button>
              </div>
            </>
          ) : (
            <>
              <Alert>{result.message}</Alert>
              <p className="text-sm text-slate-600">No money was moved.</p>
              <div className="flex justify-end">
                <Button onClick={() => reset(true)}>Back to edit</Button>
              </div>
            </>
          )}
        </div>
      )}
    </section>
  )
}
