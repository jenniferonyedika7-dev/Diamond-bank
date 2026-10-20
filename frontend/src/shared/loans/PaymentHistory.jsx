import { formatDateTime } from '../../lib/format.js'
import { CHANNEL_LABELS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'

/** What a payment paid, from the instalment numbers the API lists for it. */
function covered(payment) {
  const numbers = payment.instalments.map((n) => `#${n}`).join(', ')
  if (payment.payment_type === 'INSTALMENT') return `Instalment ${numbers}`
  return `Paid everything${numbers ? `: ${numbers}` : ''}`
}

function Waived({ payment }) {
  if (Number(payment.interest_waived) <= 0) return null
  return <span className="block text-xs text-slate-500">{formatMoney(payment.interest_waived)} interest waived</span>
}

/** Online payments name the account; cash payments the branch (and, for staff, who took the cash). */
function source(payment, staff) {
  if (payment.channel === 'ONLINE') return payment.account_number
  return [payment.branch_name, staff && payment.received_by?.name && `received by ${payment.received_by.name}`].filter(Boolean).join(', ')
}

/**
 * A loan's payments from the API (newest first), as a table from md up and
 * cards below. staff: also shows who received a cash payment.
 */
export default function PaymentHistory({ payments, staff = false }) {
  if (payments.length === 0) return <p className="text-sm text-slate-600">No payments yet.</p>

  return (
    <>
      <ul className="space-y-2 md:hidden">
        {payments.map((payment) => (
          <li key={payment.loan_payment_id} className="card p-3">
            <div className="flex items-baseline justify-between gap-3">
              <span className="font-medium text-slate-900">{covered(payment)}</span>
              <span className="font-medium tabular-nums text-slate-900">{formatMoney(payment.amount)}</span>
            </div>
            <p className="mt-1 text-xs text-slate-500">
              {formatDateTime(payment.payment_date)} · {CHANNEL_LABELS[payment.channel] ?? payment.channel}
              {source(payment, staff) && ` · ${source(payment, staff)}`}
            </p>
            <Waived payment={payment} />
            <p className="mt-1 text-xs text-slate-500">Loan balance after: {formatMoney(payment.remaining_balance)}</p>
          </li>
        ))}
      </ul>

      <div className="card hidden overflow-x-auto md:block">
        <table className="data-table">
          <thead>
            <tr>
              <th scope="col" className="px-4 py-3">Date</th>
              <th scope="col" className="px-4 py-3 text-right">Amount</th>
              <th scope="col" className="px-4 py-3">Channel</th>
              <th scope="col" className="px-4 py-3">Covered</th>
              <th scope="col" className="px-4 py-3 text-right">Loan balance after</th>
            </tr>
          </thead>
          <tbody>
            {payments.map((payment) => (
              <tr key={payment.loan_payment_id}>
                <td className="whitespace-nowrap px-4 py-3 text-slate-700">{formatDateTime(payment.payment_date)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900">{formatMoney(payment.amount)}</td>
                <td className="px-4 py-3 text-slate-700">
                  {CHANNEL_LABELS[payment.channel] ?? payment.channel}
                  {source(payment, staff) && <span className="block text-xs text-slate-500">{source(payment, staff)}</span>}
                </td>
                <td className="px-4 py-3 text-slate-700">
                  {covered(payment)}
                  <Waived payment={payment} />
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{formatMoney(payment.remaining_balance)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}
