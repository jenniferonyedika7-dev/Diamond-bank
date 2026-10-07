import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { formatPercent } from '../../lib/format.js'
import {
  EMAIL_PATTERN,
  EMPLOYMENT_FIELD_LABELS,
  EMPLOYMENT_LABELS,
  PHONE_PATTERN,
  PLAN_HELP,
  PLAN_LABELS,
  PURPOSE_LABELS,
  TIN_PATTERN,
} from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { useDebounced } from '../../lib/useApiList.js'
import { Field, LoanTermsFields } from '../../shared/loans/LoanParts.jsx'
import ScheduleTable from '../../shared/loans/ScheduleTable.jsx'
import { canBank, useCustomerData } from '../customerData.js'
import KycNotice from '../KycNotice.jsx'

const STEPS = ['Loan details', 'Employment and income', 'Guarantor', 'Review and submit']
const AMOUNT = /^\d+(\.\d{1,2})?$/
const PHONE_HINT = 'Digits and spaces, with an optional leading +.'
const GUARANTOR_FIELDS = [
  { key: 'full_name', label: 'Full name', maxLength: 150, autoComplete: 'off' },
  { key: 'phone', label: 'Phone', maxLength: 20, type: 'tel', hint: PHONE_HINT },
  { key: 'occupation', label: 'Occupation', maxLength: 100 },
  { key: 'address', label: 'Address', maxLength: 255 },
  { key: 'email', label: 'Email', maxLength: 150, type: 'email' },
]
// The backend's max lengths for the employment fields.
const EMPLOYMENT_MAX = {
  employer_name: 150,
  workplace_address: 255,
  employer_phone: 20,
  job_title: 100,
  business_name: 150,
  business_registration_number: 50,
  platform: 50,
  account_handle: 100,
  employment_description: 500,
}
const STEP2_KEYS = new Set(['monthly_income', 'tin', 'employment_type', ...Object.keys(EMPLOYMENT_FIELD_LABELS)])

/** The step (1-4) that holds a field, for jumping to a 422 error. */
function stepOf(field) {
  if (field.startsWith('guarantor')) return 3
  if (STEP2_KEYS.has(field)) return 2
  return 1
}

const EMPTY_FORM = {
  loan_type_id: '',
  account_number: '',
  amount: '',
  term_months: '',
  repayment_plan: 'MONTHLY',
  purpose_category: '',
  purpose_description: '',
  monthly_income: '',
  tin: '',
  employment_type: '',
  ...Object.fromEntries(Object.keys(EMPLOYMENT_FIELD_LABELS).map((key) => [key, ''])),
  guarantor: Object.fromEntries(GUARANTOR_FIELDS.map((f) => [f.key, ''])),
}

export default function ApplyLoanPage() {
  const { overview } = useCustomerData()
  const { customer, accounts } = overview
  const [context, setContext] = useState(null)
  const [loadError, setLoadError] = useState('')

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/customer/loans/apply-context')
      .then(({ data }) => !ignore && setContext(data.data))
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [])

  const back = (
    <Link to="/customer/loans" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
      ← Loans
    </Link>
  )

  let blocked = null
  if (!canBank(customer, accounts)) blocked = <KycNotice customer={customer} accounts={accounts} />
  else if (loadError) blocked = <Alert>{loadError}</Alert>
  else if (!context) blocked = <Loading />
  else if (context.has_open_loan) {
    blocked = (
      <Alert tone="info">
        You already have a loan application in progress or an active loan. You can have one loan at a time.
      </Alert>
    )
  } else if (context.loan_types.length === 0) blocked = <Alert tone="info">Loans are not available yet. Please check again later.</Alert>
  else if (context.accounts.length === 0) {
    blocked = <Alert tone="info">None of your accounts can receive a loan right now. Please contact your branch.</Alert>
  }

  return (
    <section className="max-w-3xl space-y-6">
      {back}
      <PageHeader title="Apply for a loan" />
      {blocked ?? <ApplyForm context={context} />}
    </section>
  )
}

function ApplyForm({ context }) {
  const navigate = useNavigate()
  const { limits, employment_fields: employmentFields } = context
  const [step, setStep] = useState(1)
  const [form, setForm] = useState({ ...EMPTY_FORM, account_number: context.accounts[0]?.account_number ?? '' })
  const [fieldErrors, setFieldErrors] = useState({})
  const [error, setError] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const submittingRef = useRef(false) // blocks a second submit before React re-renders the disabled button
  const headingRef = useRef(null)
  const firstRender = useRef(true)

  // The live quote: only for complete terms, after the customer stops typing. Each result
  // is tagged with the terms it answers, so a stale one is never shown as current.
  const terms = {
    loan_type_id: form.loan_type_id,
    amount: form.amount.trim(),
    term_months: form.term_months,
    repayment_plan: form.repayment_plan,
  }
  const termsKey = JSON.stringify(terms)
  const termsComplete = Boolean(terms.loan_type_id && AMOUNT.test(terms.amount) && terms.term_months && terms.repayment_plan)
  const debouncedKey = useDebounced(termsKey, 600)
  const [quote, setQuote] = useState({ key: null, data: null, error: '', fieldErrors: {} })

  useEffect(() => {
    const t = JSON.parse(debouncedKey)
    if (!(t.loan_type_id && AMOUNT.test(t.amount) && t.term_months && t.repayment_plan)) return undefined
    let ignore = false
    api
      .post('/api/v1/customer/loans/quote', { ...t, loan_type_id: Number(t.loan_type_id), term_months: Number(t.term_months) })
      .then(({ data }) => !ignore && setQuote({ key: debouncedKey, data: data.data, error: '', fieldErrors: {} }))
      .catch((err) => {
        if (ignore) return
        const message =
          err.response?.status === 429 ? 'Too many quotes. Wait a moment, then change a value to try again.' : getErrorMessage(err)
        setQuote({ key: debouncedKey, data: null, error: message, fieldErrors: getFieldErrors(err) })
      })
    return () => {
      ignore = true
    }
  }, [debouncedKey])

  const currentQuote = termsComplete && quote.key === termsKey ? quote : null
  const quoting = termsComplete && !currentQuote

  // Move focus to the step heading so screen reader and keyboard users start at the top of the new step.
  useEffect(() => {
    if (firstRender.current) {
      firstRender.current = false
      return
    }
    headingRef.current?.focus()
  }, [step])

  const set = (name, value) => setForm((prev) => ({ ...prev, [name]: value }))
  const update = (event) => set(event.target.name, event.target.value)
  const updateGuarantor = (key) => (event) => setForm((prev) => ({ ...prev, guarantor: { ...prev.guarantor, [key]: event.target.value } }))

  // Step 1 shows the quote's 422 messages until the customer presses Next.
  const errors = step === 1 ? { ...(currentQuote?.fieldErrors ?? {}), ...fieldErrors } : fieldErrors
  const activeEmploymentFields = employmentFields[form.employment_type] ?? []
  const type = context.loan_types.find((t) => String(t.loan_type_id) === String(form.loan_type_id))
  const account = context.accounts.find((a) => a.account_number === form.account_number)

  function validate(n) {
    const e = {}
    const required = (key, message) => {
      if (!String(form[key]).trim()) e[key] = message
    }
    if (n === 1) {
      required('loan_type_id', 'Choose a loan type.')
      required('account_number', 'Choose the account the loan is paid into.')
      if (!AMOUNT.test(form.amount.trim()) || Number(form.amount) <= 0) e.amount = 'Enter an amount, with at most 2 decimal places.'
      required('term_months', 'Choose a term.')
      required('repayment_plan', 'Choose how you want to repay.')
      required('purpose_category', 'Choose what the loan is for.')
      required('purpose_description', 'Describe what the loan is for.')
    }
    if (n === 2) {
      if (!AMOUNT.test(form.monthly_income.trim()) || Number(form.monthly_income) <= 0) {
        e.monthly_income = 'Enter your monthly income, with at most 2 decimal places.'
      }
      if (!TIN_PATTERN.test(form.tin.trim())) e.tin = 'The TIN must be 8 to 15 digits.'
      required('employment_type', 'Choose your employment type.')
      for (const key of activeEmploymentFields) required(key, `Enter the ${EMPLOYMENT_FIELD_LABELS[key].toLowerCase()}.`)
      if (activeEmploymentFields.includes('employer_phone') && form.employer_phone.trim() && !PHONE_PATTERN.test(form.employer_phone.trim())) {
        e.employer_phone = `Enter a valid phone number. ${PHONE_HINT}`
      }
    }
    if (n === 3) {
      const g = form.guarantor
      for (const f of GUARANTOR_FIELDS) {
        if (!g[f.key].trim()) e[`guarantor.${f.key}`] = `Enter the guarantor's ${f.label.toLowerCase()}.`
      }
      const digits = (value) => String(value ?? '').replace(/\D/g, '')
      if (g.phone.trim() && !PHONE_PATTERN.test(g.phone.trim())) e['guarantor.phone'] = `Enter a valid phone number. ${PHONE_HINT}`
      else if (g.phone.trim() && digits(g.phone) === digits(context.contact.phone)) {
        e['guarantor.phone'] = "A guarantor can't be yourself: use someone else's phone number."
      }
      if (g.email.trim() && !EMAIL_PATTERN.test(g.email.trim())) e['guarantor.email'] = 'Enter a valid email address.'
      else if (g.email.trim() && g.email.trim().toLowerCase() === String(context.contact.email ?? '').trim().toLowerCase()) {
        e['guarantor.email'] = "A guarantor can't be yourself: use someone else's email."
      }
    }
    return e
  }

  function next() {
    const e = validate(step)
    setFieldErrors(e)
    setError('')
    if (Object.keys(e).length > 0) return
    if (step === 1 && !currentQuote?.data) {
      // The backend checks the terms (limits, rate) through the quote; wait for a good one.
      setError(currentQuote?.error || (quoting ? 'Wait for the quote to finish, then press Next.' : 'Check the loan terms.'))
      return
    }
    setStep(step + 1)
  }

  function handleNext(event) {
    event.preventDefault()
    next()
  }

  function goTo(n) {
    setFieldErrors({})
    setError('')
    setStep(n)
  }

  async function handleSubmit(event) {
    event.preventDefault()
    if (submittingRef.current || !confirmed) return
    submittingRef.current = true
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    const employment = Object.fromEntries(activeEmploymentFields.map((key) => [key, form[key].trim()]))
    try {
      const { data } = await api.post('/api/v1/customer/loans', {
        loan_type_id: Number(form.loan_type_id),
        account_number: form.account_number,
        amount: form.amount.trim(),
        term_months: Number(form.term_months),
        repayment_plan: form.repayment_plan,
        purpose_category: form.purpose_category,
        purpose_description: form.purpose_description.trim(),
        monthly_income: form.monthly_income.trim(),
        tin: form.tin.trim(),
        employment_type: form.employment_type,
        ...employment,
        guarantor: Object.fromEntries(Object.entries(form.guarantor).map(([key, value]) => [key, value.trim()])),
      })
      navigate('/customer/loans', { state: { flash: data.message } })
      return
    } catch (err) {
      const fields = getFieldErrors(err)
      const keys = Object.keys(fields)
      if (keys.length > 0) {
        // Back to the earliest step with a problem, with the messages under its fields.
        setFieldErrors(fields)
        setStep(Math.min(...keys.map(stepOf)))
      } else {
        setError(getErrorMessage(err))
      }
    }
    submittingRef.current = false
    setSubmitting(false)
  }

  return (
    <div className="space-y-6">
      <ol className="grid grid-cols-2 gap-2 sm:grid-cols-4" aria-label="Application steps">
        {STEPS.map((label, i) => {
          const n = i + 1
          const state = n < step ? 'done' : n === step ? 'current' : 'todo'
          return (
            <li
              key={label}
              aria-current={state === 'current' ? 'step' : undefined}
              className={`rounded-lg border-t-4 bg-white px-3 py-2 text-xs shadow-sm ring-1 ring-slate-200 ${
                state === 'todo' ? 'border-slate-200 text-slate-500' : 'border-navy-900 text-navy-900'
              }`}
            >
              <span className="block font-semibold">Step {n}</span>
              {label}
              {state === 'done' && <span className="sr-only"> (done)</span>}
            </li>
          )
        })}
      </ol>

      <form
        onSubmit={step === 4 ? handleSubmit : handleNext}
        noValidate
        className="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"
      >
        <h2 ref={headingRef} tabIndex={-1} className="text-lg font-semibold text-navy-900 focus:outline-none" aria-live="polite">
          Step {step} of 4: {STEPS[step - 1]}
        </h2>
        <Alert>{error}</Alert>

        {step === 1 && (
          <>
            <FormField id="loan_type_id" label="Loan type" required value={form.loan_type_id} onChange={update} error={errors.loan_type_id}>
              <option value="">Select a loan type…</option>
              {context.loan_types.map((t) => (
                <option key={t.loan_type_id} value={t.loan_type_id}>
                  {t.type_name}: {formatPercent(t.interest_rate)} a year
                </option>
              ))}
            </FormField>
            <FormField
              id="account_number"
              label="Pay the loan into"
              required
              value={form.account_number}
              onChange={update}
              error={errors.account_number}
              hint="Only your active accounts are listed."
            >
              {context.accounts.map((a) => (
                <option key={a.account_number} value={a.account_number}>
                  {a.type_name} · {a.account_number}
                </option>
              ))}
            </FormField>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField
                id="amount"
                label="Amount (GMD)"
                type="number"
                inputMode="decimal"
                step="0.01"
                min={limits.min_amount}
                max={limits.max_amount}
                required
                value={form.amount}
                onChange={update}
                error={errors.amount}
                hint={`From ${formatMoney(limits.min_amount)} to ${formatMoney(limits.max_amount)}.`}
              />
              <FormField id="term_months" label="Term" required value={form.term_months} onChange={update} error={errors.term_months}>
                <option value="">Select a term…</option>
                {Array.from({ length: limits.max_term_months - limits.min_term_months + 1 }, (_, i) => limits.min_term_months + i).map((m) => (
                  <option key={m} value={m}>
                    {m} {m === 1 ? 'month' : 'months'}
                  </option>
                ))}
              </FormField>
            </div>
            <fieldset aria-describedby={errors.repayment_plan ? 'repayment_plan-error' : undefined}>
              <legend className="mb-1.5 block text-sm font-medium text-slate-700">Repayment plan</legend>
              <div className="grid gap-3 sm:grid-cols-2">
                {Object.keys(PLAN_LABELS).map((plan) => (
                  <label
                    key={plan}
                    className={`flex cursor-pointer gap-3 rounded-lg border p-3 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-navy-600 ${
                      form.repayment_plan === plan ? 'border-navy-900 bg-navy-50' : 'border-slate-300'
                    }`}
                  >
                    <input
                      type="radio"
                      name="repayment_plan"
                      value={plan}
                      checked={form.repayment_plan === plan}
                      onChange={update}
                      className="mt-0.5 accent-navy-900"
                    />
                    <span>
                      <span className="block font-medium text-slate-900">{PLAN_LABELS[plan]}</span>
                      <span className="text-slate-600">{PLAN_HELP[plan]}</span>
                    </span>
                  </label>
                ))}
              </div>
              {errors.repayment_plan && (
                <p id="repayment_plan-error" className="mt-1 text-sm text-red-700">
                  {errors.repayment_plan}
                </p>
              )}
            </fieldset>

            <QuotePanel quote={currentQuote} quoting={quoting} complete={termsComplete} />

            <FormField id="purpose_category" label="What is the loan for?" required value={form.purpose_category} onChange={update} error={errors.purpose_category}>
              <option value="">Select a purpose…</option>
              {context.purposes.map((p) => (
                <option key={p} value={p}>
                  {PURPOSE_LABELS[p] ?? p}
                </option>
              ))}
            </FormField>
            <FormField
              id="purpose_description"
              as="textarea"
              label="Describe the purpose"
              required
              maxLength={500}
              value={form.purpose_description}
              onChange={update}
              error={errors.purpose_description}
              hint={`${form.purpose_description.length}/500 characters.`}
            />
          </>
        )}

        {step === 2 && (
          <>
            <div className="grid gap-5 sm:grid-cols-2">
              <FormField
                id="monthly_income"
                label="Monthly income (GMD)"
                type="number"
                inputMode="decimal"
                step="0.01"
                min="0.01"
                required
                value={form.monthly_income}
                onChange={update}
                error={errors.monthly_income}
              />
              <FormField
                id="tin"
                label="TIN (Taxpayer Identification Number)"
                inputMode="numeric"
                maxLength={15}
                autoComplete="off"
                required
                value={form.tin}
                onChange={update}
                error={errors.tin}
                hint="8 to 15 digits."
              />
            </div>
            <FormField id="employment_type" label="Employment" required value={form.employment_type} onChange={update} error={errors.employment_type}>
              <option value="">Select your employment type…</option>
              {Object.keys(employmentFields).map((t) => (
                <option key={t} value={t}>
                  {EMPLOYMENT_LABELS[t] ?? t}
                </option>
              ))}
            </FormField>
            {activeEmploymentFields.map((key) => (
              <FormField
                key={key}
                id={key}
                label={EMPLOYMENT_FIELD_LABELS[key]}
                as={key === 'employment_description' ? 'textarea' : undefined}
                type={key === 'employer_phone' ? 'tel' : undefined}
                maxLength={EMPLOYMENT_MAX[key]}
                required
                value={form[key]}
                onChange={update}
                error={errors[key]}
                hint={key === 'employer_phone' ? PHONE_HINT : undefined}
              />
            ))}
            <div className="rounded-lg bg-slate-50 p-4 text-sm">
              <p className="font-medium text-slate-700">Your contact details</p>
              <dl className="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                <Field label="Phone" value={context.contact.phone} />
                <Field label="Email" value={context.contact.email} />
              </dl>
              <p className="mt-2 text-xs text-slate-500">If these are wrong, ask your branch to update your profile.</p>
            </div>
          </>
        )}

        {step === 3 && (
          <>
            <Alert tone="info">
              The bank will contact your guarantor to confirm they know about this loan and agree to stand behind it. Your guarantor
              can&apos;t be you.
            </Alert>
            {GUARANTOR_FIELDS.map((f) => (
              <FormField
                key={f.key}
                id={`guarantor-${f.key}`}
                label={`Guarantor's ${f.label.toLowerCase()}`}
                type={f.type}
                maxLength={f.maxLength}
                autoComplete={f.autoComplete ?? 'off'}
                required
                value={form.guarantor[f.key]}
                onChange={updateGuarantor(f.key)}
                error={errors[`guarantor.${f.key}`]}
                hint={f.hint}
              />
            ))}
          </>
        )}

        {step === 4 && currentQuote?.data && (
          <>
            <ReviewSection title="Loan details" onEdit={() => goTo(1)}>
              <Field label="Loan type" value={type?.type_name} />
              <Field label="Paid into" value={account && `${account.type_name} · ${account.account_number}`} />
              <LoanTermsFields loan={currentQuote.data} />
              <Field label="Number of payments" value={currentQuote.data.number_of_payments} />
              <Field label="Purpose" value={PURPOSE_LABELS[form.purpose_category]} />
              <Field label="Description" value={form.purpose_description} />
            </ReviewSection>
            <ReviewSection title="Employment and income" onEdit={() => goTo(2)}>
              <Field label="Monthly income" value={formatMoney(form.monthly_income)} />
              <Field label="TIN" value={form.tin} />
              <Field label="Employment" value={EMPLOYMENT_LABELS[form.employment_type]} />
              {activeEmploymentFields.map((key) => (
                <Field key={key} label={EMPLOYMENT_FIELD_LABELS[key]} value={form[key]} />
              ))}
            </ReviewSection>
            <ReviewSection title="Guarantor" onEdit={() => goTo(3)}>
              {GUARANTOR_FIELDS.map((f) => (
                <Field key={f.key} label={f.label} value={form.guarantor[f.key]} />
              ))}
            </ReviewSection>
            <div>
              <h3 className="mb-2 text-sm font-semibold text-slate-700">Repayment schedule (preview)</h3>
              <ScheduleTable schedule={currentQuote.data.schedule} estimated />
            </div>
            <label className="flex items-start gap-3 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={confirmed}
                onChange={(e) => setConfirmed(e.target.checked)}
                className="mt-0.5 size-4 accent-navy-900"
              />
              <span>
                I confirm these details are true, and I understand the bank will check them, review my bank statement and contact my
                guarantor before deciding.
              </span>
            </label>
          </>
        )}
        {step === 4 && !currentQuote?.data && (
          <Alert tone="info">The quote has changed. Go back to step 1 to get a new one.</Alert>
        )}

        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-between">
          {step > 1 ? (
            <Button variant="secondary" disabled={submitting} onClick={() => goTo(step - 1)}>
              Back
            </Button>
          ) : (
            <span />
          )}
          {step < 4 ? (
            <Button type="submit">Next</Button>
          ) : (
            <Button type="submit" loading={submitting} loadingText="Submitting…" disabled={!confirmed || !currentQuote?.data}>
              Submit application
            </Button>
          )}
        </div>
      </form>
    </div>
  )
}

/** The backend's quote for the current terms. Nothing here is calculated in the browser. */
function QuotePanel({ quote, quoting, complete }) {
  return (
    <div className="rounded-xl bg-navy-50 p-4" aria-live="polite">
      <p className="text-sm font-semibold text-navy-900">Your quote</p>
      {!complete ? (
        <p className="mt-1 text-sm text-navy-800">Choose a loan type, amount, term and plan to see what you would repay.</p>
      ) : quoting ? (
        <p className="mt-1 text-sm text-navy-800">Working out your quote…</p>
      ) : quote?.data ? (
        <dl className="mt-2 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
          <QuoteFigure
            label={quote.data.repayment_plan === 'MONTHLY' ? 'Monthly instalment' : 'Single payment'}
            value={formatMoney(quote.data.repayment_plan === 'MONTHLY' ? quote.data.monthly_instalment : quote.data.total_repayable)}
          />
          <QuoteFigure label="Number of payments" value={quote.data.number_of_payments} />
          <QuoteFigure label="Total interest" value={formatMoney(quote.data.total_interest)} />
          <QuoteFigure label="Total repayable" value={formatMoney(quote.data.total_repayable)} />
        </dl>
      ) : (
        <p className="mt-1 text-sm text-red-700">{quote?.error || 'No quote for these terms.'}</p>
      )}
    </div>
  )
}

function QuoteFigure({ label, value }) {
  return (
    <div>
      <dt className="text-navy-800">{label}</dt>
      <dd className="mt-0.5 font-semibold tabular-nums text-navy-900">{value}</dd>
    </div>
  )
}

function ReviewSection({ title, onEdit, children }) {
  return (
    <div className="border-b border-slate-100 pb-5">
      <div className="mb-3 flex items-center justify-between gap-3">
        <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
        <Button variant="secondary" className="py-1" onClick={onEdit}>
          Edit<span className="sr-only"> {title.toLowerCase()}</span>
        </Button>
      </div>
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">{children}</dl>
    </div>
  )
}
