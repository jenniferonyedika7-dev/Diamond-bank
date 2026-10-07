import { InstalmentStatusBadge } from '../../components/StatusBadges.jsx'
import { formatDate } from '../../lib/format.js'
import { formatMoney } from '../../lib/money.js'

/**
 * A repayment schedule from the API, as a table from md up and cards below.
 * estimated: the dates are a preview (the apply form); the real ones are set on approval.
 * Rows with display_status get a status badge.
 */
export default function ScheduleTable({ schedule, estimated = false }) {
  const showStatus = schedule.some((row) => row.display_status)
  const due = (row) => (
    <>
      {formatDate(row.due_date)}
      {estimated && <span className="text-slate-500"> (est.)</span>}
    </>
  )

  return (
    <>
      <ul className="space-y-2 md:hidden">
        {schedule.map((row) => (
          <li key={row.instalment_number} className="rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200">
            <div className="flex items-baseline justify-between gap-3">
              <span className="font-medium text-slate-900">
                #{row.instalment_number} · {due(row)}
              </span>
              <span className="font-medium tabular-nums text-slate-900">{formatMoney(row.amount)}</span>
            </div>
            <p className="mt-1 text-xs text-slate-500">
              Principal {formatMoney(row.principal)} · interest {formatMoney(row.interest)} · balance after {formatMoney(row.balance_after)}
            </p>
            {showStatus && (
              <p className="mt-1">
                <InstalmentStatusBadge status={row.display_status} />
              </p>
            )}
          </li>
        ))}
      </ul>

      <div className="hidden overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
            <tr>
              <th scope="col" className="px-4 py-3">#</th>
              <th scope="col" className="px-4 py-3">Due date</th>
              <th scope="col" className="px-4 py-3 text-right">Principal</th>
              <th scope="col" className="px-4 py-3 text-right">Interest</th>
              <th scope="col" className="px-4 py-3 text-right">Amount</th>
              <th scope="col" className="px-4 py-3 text-right">Balance after</th>
              {showStatus && <th scope="col" className="px-4 py-3">Status</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {schedule.map((row) => (
              <tr key={row.instalment_number}>
                <td className="px-4 py-3 text-slate-700">{row.instalment_number}</td>
                <td className="whitespace-nowrap px-4 py-3 text-slate-700">{due(row)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{formatMoney(row.principal)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{formatMoney(row.interest)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right font-medium tabular-nums text-slate-900">{formatMoney(row.amount)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{formatMoney(row.balance_after)}</td>
                {showStatus && (
                  <td className="px-4 py-3">
                    <InstalmentStatusBadge status={row.display_status} />
                  </td>
                )}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {estimated && (
        <p className="mt-2 text-xs text-slate-500">Due dates are estimates. The real dates count from the day the loan is approved.</p>
      )}
    </>
  )
}
