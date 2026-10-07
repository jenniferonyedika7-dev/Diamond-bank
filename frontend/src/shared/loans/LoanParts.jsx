import { formatPercent } from '../../lib/format.js'
import { PLAN_LABELS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'

/** A white panel with a heading, used for each section of the loan pages. */
export function Panel({ title, action, children, className = '' }) {
  return (
    <section className={`rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 ${className}`}>
      <div className="mb-4 flex items-center justify-between gap-3">
        <h2 className="text-lg font-semibold text-navy-900">{title}</h2>
        {action}
      </div>
      {children}
    </section>
  )
}

export function Field({ label, value }) {
  return (
    <div>
      <dt className="text-slate-500">{label}</dt>
      <dd className="mt-0.5 break-words text-slate-900">{value || '—'}</dd>
    </div>
  )
}

/** "GMD 888.49 a month" or "one payment at the end": the instalment exactly as the API gave it. */
function instalmentText(loan) {
  return loan.repayment_plan === 'MONTHLY' ? `${formatMoney(loan.monthly_instalment)} a month` : 'One payment at the end'
}

/** The terms every loan view shows. loan: a loan or quote from the API. */
export function LoanTermsFields({ loan }) {
  return (
    <>
      <Field label="Amount" value={formatMoney(loan.amount)} />
      <Field label="Interest rate" value={`${formatPercent(loan.interest_rate)} a year`} />
      <Field label="Term" value={`${loan.term_months} ${Number(loan.term_months) === 1 ? 'month' : 'months'}`} />
      <Field label="Repayment plan" value={PLAN_LABELS[loan.repayment_plan] ?? loan.repayment_plan} />
      <Field label="Instalment" value={instalmentText(loan)} />
      <Field label="Total interest" value={formatMoney(loan.total_interest)} />
      <Field label="Total repayable" value={formatMoney(loan.total_repayable)} />
    </>
  )
}
