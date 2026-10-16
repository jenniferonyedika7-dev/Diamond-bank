import Alert from '../../components/Alert.jsx'
import { formatDate } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'
import { Field } from './LoanParts.jsx'

/**
 * Where an ACTIVE loan stands, from the repayment quote: what is left to pay,
 * the next payment and any overdue instalments. Overdue is derived from the
 * due dates; no penalty is charged. actions: the pay buttons.
 */
export default function RepaymentSummary({ quote, actions }) {
  const next = quote.next_instalment

  return (
    <div className="space-y-4">
      {quote.overdue_count > 0 && (
        <Alert>
          {quote.overdue_count === 1 ? '1 instalment is' : `${quote.overdue_count} instalments are`} overdue. Please pay as soon as you
          can. No penalty fees are charged.
        </Alert>
      )}
      <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
        <Field label="Outstanding balance" value={formatMoney(quote.outstanding)} />
        {next ? (
          <Field
            label="Next payment"
            value={
              <>
                <span className="font-medium tabular-nums">{formatMoney(next.amount)}</span> due {formatDate(next.due_date)}
                {next.overdue && <span className="font-medium text-red-700"> (overdue)</span>}
              </>
            }
          />
        ) : (
          quote.payoff && <Field label="Pay everything now" value={formatMoney(quote.payoff.amount)} />
        )}
        {quote.repayment_plan === 'MONTHLY' && <Field label="Instalments left" value={String(quote.unpaid_count)} />}
      </dl>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}
