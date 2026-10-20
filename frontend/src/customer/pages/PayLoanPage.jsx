import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import { buttonClass } from '../../components/buttonStyles.js'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'
import { PAYMENT_TYPE_LABELS, paymentBody, paymentTypesFor, quotedAmount } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

const STEPS = { details: 'Step 1 of 3: What to pay', review: 'Step 2 of 3: Review and confirm', result: 'Step 3 of 3: Result' }


/** How much can leave the account without going below its minimum balance (display only, as on the transfer page). */
function available(account) {
  return Math.max(Number(account.balance) - Number(account.minimum_balance), 0)
}

/**
 * Pays an ACTIVE loan online from one of the customer's accounts: choose
 * what to pay, confirm with the password, see the result. Every amount comes
 * from the repayment quote; sp_repay_loan checks it again and answers 409 if
 * it changed.
 */
export default function PayLoanPage() {
  const { loanId } = useParams()
  const [searchParams] = useSearchParams()
  const { overview, reload } = useCustomerData()
  const { customer, accounts } = overview

  const [loan, setLoan] = useState(null)
  const [quote, setQuote] = useState(null)
  const [loadError, setLoadError] = useState('')
  const [step, setStep] = useState('details')
  const [type, setType] = useState(searchParams.get('type') === 'INSTALMENT' ? 'INSTALMENT' : 'EARLY_PAYOFF')
  const [accountNumber, setAccountNumber] = useState('')
  const [password, setPassword] = useState('')
  const [pending, setPending] = useState(false)
  const submittingRef = useRef(false) // blocks a second submit before React re-renders the disabled button
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [result, setResult] = useState(null) // { ok: true, data, message } | { ok: false, message, retry }

  const loadQuote = useCallback(
    () =>
      api.get(`/api/v1/customer/loans/${loanId}/repayment`).then(({ data }) => {
        const next = data.data
        setQuote(next)
        // A SINGLE loan (no next instalment) can only be paid off.
        setType((prev) => (paymentTypesFor(next.repayment_plan).includes(prev) && quotedAmount(next, prev) ? prev : 'EARLY_PAYOFF'))
        setAccountNumber((prev) => (next.accounts.some((a) => a.account_number === prev) ? prev : (next.accounts[0]?.account_number ?? '')))
        return next
      }),
    [loanId],
  )

  useEffect(() => {
    Promise.all([api.get(`/api/v1/customer/loans/${loanId}`).then(({ data }) => setLoan(data.data)), loadQuote()]).catch((err) =>
      setLoadError(getErrorMessage(err)),
    )
  }, [loanId, loadQuote])

  const backLink = (
    <Link to={`/customer/loans/${loanId}`} className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
      ← Back to the loan
    </Link>
  )

  if (!canBank(customer, accounts)) {
    return (
      <section className="space-y-6">
        <PageHeader title="Pay your loan" />
        <KycNotice customer={customer} accounts={accounts} />
      </section>
    )
  }
  if (loadError) {
    return (
      <section className="max-w-xl space-y-4">
        {backLink}
        <Alert>{loadError}</Alert>
      </section>
    )
  }
  if (!loan || !quote) return <Loading />

  const source = quote.accounts.find((a) => a.account_number === accountNumber)
  const amount = quotedAmount(quote, type)
  const types = paymentTypesFor(quote.repayment_plan).filter((t) => quotedAmount(quote, t))

  function reset() {
    setStep('details')
    setPassword('')
    setError('')
    setNotice('')
    setFieldErrors({})
    setResult(null)
  }

  function handleContinue(event) {
    event.preventDefault()
    setError('')
    setNotice('')
    if (!source) {
      setFieldErrors({ account_number: 'Choose the account to pay from.' })
      return
    }
    setFieldErrors({})
    setStep('review')
  }

  async function handlePay(event) {
    event.preventDefault()
    if (submittingRef.current) return
    submittingRef.current = true
    setPending(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post(`/api/v1/customer/loans/${loanId}/payments`, {
        ...paymentBody(quote, type),
        account_number: accountNumber,
        password,
      })
      setResult({ ok: true, data: data.data, message: data.message })
      setStep('result')
      reload()
    } catch (err) {
      const status = err.response?.status
      const errors = getFieldErrors(err)
      if (errors.password) {
        setFieldErrors({ password: errors.password })
      } else if (status === 422 && Object.keys(errors).length > 0) {
        // A details problem (the account, the amount): back to step 1 with the messages under the fields.
        setFieldErrors(errors)
        setStep('details')
      } else if (status === 409 && getErrorMessage(err).startsWith('The amount has changed')) {
        // The schedule moved on (e.g. an instalment became overdue): show the new figures.
        try {
          await loadQuote()
          setNotice(`${getErrorMessage(err)} The amounts below are up to date.`)
          setStep('details')
        } catch (reloadErr) {
          setResult({ ok: false, message: getErrorMessage(reloadErr) })
          setStep('result')
        }
      } else if (status === 422 || status === 409) {
        // 422: insufficient funds, a frozen account (worth another try). 409: the loan is no longer active.
        setResult({ ok: false, message: getErrorMessage(err), retry: status === 422 })
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
      <div>
        {backLink}
        <div className="mt-2">
          <PageHeader title={`Pay your ${loan.loan_type.type_name}`} description={`Loan #${loan.loan_id}`} />
        </div>
      </div>
      <p className="text-sm font-medium text-slate-600" aria-live="polite">
        {STEPS[step]}
      </p>

      {step === 'details' && (
        <form onSubmit={handleContinue} noValidate className="card space-y-5 p-6">
          <Alert>{error}</Alert>
          <Alert tone="info">{notice}</Alert>
          {quote.overdue_count > 0 && (
            <Alert>
              {quote.overdue_count === 1 ? '1 instalment is' : `${quote.overdue_count} instalments are`} overdue. No penalty fees are
              charged.
            </Alert>
          )}

          <fieldset>
            <legend className="mb-2 text-sm font-medium text-slate-700">What do you want to pay?</legend>
            <div className="space-y-2">
              {types.map((t) => (
                <label
                  key={t}
                  className={`flex cursor-pointer items-start gap-3 rounded-lg p-3 ring-1 ring-inset ${
                    type === t ? 'bg-navy-50 ring-navy-600' : 'ring-slate-300 hover:bg-slate-50'
                  }`}
                >
                  <input
                    type="radio"
                    name="payment_type"
                    value={t}
                    checked={type === t}
                    onChange={() => setType(t)}
                    className="mt-1"
                  />
                  <span className="min-w-0 text-sm">
                    <span className="flex flex-wrap justify-between gap-2 font-medium text-slate-900">
                      {PAYMENT_TYPE_LABELS[t]}
                      <span className="tabular-nums">{formatMoney(quotedAmount(quote, t))}</span>
                    </span>
                    <OptionDetail quote={quote} type={t} />
                  </span>
                </label>
              ))}
            </div>
            {fieldErrors.payment_type && <p className="mt-1 text-sm text-red-700">{fieldErrors.payment_type}</p>}
            {fieldErrors.amount && <p className="mt-1 text-sm text-red-700">{fieldErrors.amount}</p>}
          </fieldset>

          {quote.accounts.length === 0 ? (
            <Alert tone="info">None of your accounts can make payments right now. Please contact your branch.</Alert>
          ) : (
            <>
              <FormField
                id="account_number"
                label="Pay from account"
                value={accountNumber}
                onChange={(e) => setAccountNumber(e.target.value)}
                error={fieldErrors.account_number}
                hint={
                  source
                    ? `Available: ${formatMoney(available(source), source.currency_code)} (keeps the ${formatMoney(source.minimum_balance, source.currency_code)} minimum).`
                    : undefined
                }
              >
                {quote.accounts.map((a) => (
                  <option key={a.account_number} value={a.account_number}>
                    {a.type_name} · {a.account_number} · {formatMoney(a.balance, a.currency_code)}
                  </option>
                ))}
              </FormField>
              <div className="flex flex-col gap-2 pt-2 sm:flex-row sm:justify-end">
                <Button type="submit">
                  Continue
                </Button>
              </div>
            </>
          )}
        </form>
      )}

      {step === 'review' && (
        <form onSubmit={handlePay} noValidate className="card space-y-5 p-6">
          <Alert>{error}</Alert>
          <p className="text-lg text-slate-900">
            Pay <strong className="tabular-nums">{formatMoney(amount)}</strong> towards loan #{loan.loan_id}
          </p>
          <dl className="space-y-1 text-sm">
            <div className="flex gap-2">
              <dt className="text-slate-500">Payment:</dt>
              <dd className="text-slate-900">{PAYMENT_TYPE_LABELS[type]}</dd>
            </div>
            <div className="flex gap-2">
              <dt className="text-slate-500">From:</dt>
              <dd className="text-slate-900">
                {source?.type_name} · <span className="font-mono">{accountNumber}</span>
              </dd>
            </div>
          </dl>
          <p className="text-sm text-slate-600">
            <OptionDetail quote={quote} type={type} />
          </p>
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
            <Button variant="secondary" disabled={pending} onClick={reset}>
              Back
            </Button>
            <Button type="submit" loading={pending} loadingText="Paying…" disabled={password === ''}>
              Pay {formatMoney(amount)}
            </Button>
          </div>
        </form>
      )}

      {step === 'result' && result && (
        <div className="card space-y-5 p-6">
          {result.ok ? (
            <>
              <Alert tone="success">{result.message}</Alert>
              <dl className="grid gap-3 text-sm sm:grid-cols-2">
                <div className="rounded-xl bg-navy-50 p-4">
                  <dt className="text-navy-800">New balance of {result.data.account_number}</dt>
                  <dd className="mt-1 text-2xl font-semibold tabular-nums text-navy-900">{formatMoney(result.data.balance_after)}</dd>
                </div>
                <div className="rounded-xl bg-navy-50 p-4">
                  <dt className="text-navy-800">Left to pay on the loan</dt>
                  <dd className="mt-1 text-2xl font-semibold tabular-nums text-navy-900">
                    {result.data.loan_status === 'CLOSED' ? 'Paid off' : formatMoney(result.data.remaining_balance)}
                  </dd>
                </div>
              </dl>
              <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <Link to={`/customer/accounts/${result.data.account_number}`} className={buttonClass('secondary')}>
                  View account
                </Link>
                <Link to={`/customer/loans/${loanId}`} className={buttonClass()}>
                  Back to the loan
                </Link>
              </div>
            </>
          ) : (
            <>
              <Alert>{result.message}</Alert>
              <p className="text-sm text-slate-600">No money was moved.</p>
              <div className="flex flex-col gap-2 sm:flex-row sm:justify-end">
                <Link to={`/customer/loans/${loanId}`} className={buttonClass('secondary')}>
                  Back to the loan
                </Link>
                {result.retry && <Button onClick={reset}>Try again</Button>}
              </div>
            </>
          )}
        </div>
      )}
    </section>
  )
}

/** The quote's breakdown of one option, as the API gave it. */
function OptionDetail({ quote, type }) {
  if (type === 'INSTALMENT') {
    const next = quote.next_instalment
    return (
      <span className="mt-1 block text-slate-600">
        Instalment #{next.instalment_number}, due {formatDate(next.due_date)}
        {next.overdue && <span className="font-medium text-red-700"> (overdue)</span>}: principal {formatMoney(next.principal)},
        interest {formatMoney(next.interest)}.
      </span>
    )
  }
  const payoff = quote.payoff
  return (
    <span className="mt-1 block text-slate-600">
      Clears the loan: principal {formatMoney(payoff.principal)}, interest {formatMoney(payoff.interest_charged)}.
      {Number(payoff.interest_waived) > 0 && ` ${formatMoney(payoff.interest_waived)} of future interest is waived.`}
    </span>
  )
}
