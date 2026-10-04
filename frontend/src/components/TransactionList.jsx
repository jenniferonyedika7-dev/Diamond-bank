import { formatDateTime } from '../lib/format.js'
import { formatMoney } from '../lib/money.js'

// Money coming into the account; everything else (WITHDRAWAL, TRANSFER_OUT, LOAN_PAYMENT) goes out.
const CREDITS = new Set(['DEPOSIT', 'TRANSFER_IN', 'LOAN_DISBURSEMENT'])

const LABELS = {
  DEPOSIT: 'Deposit',
  WITHDRAWAL: 'Withdrawal',
  TRANSFER_IN: 'Transfer in',
  TRANSFER_OUT: 'Transfer out',
  LOAN_DISBURSEMENT: 'Loan disbursement',
  LOAN_PAYMENT: 'Loan payment',
}

function SignedAmount({ row }) {
  const credit = CREDITS.has(row.type_name)
  return (
    <span className={`font-medium tabular-nums ${credit ? 'text-emerald-700' : 'text-red-700'}`}>
      {credit ? '+' : '−'}
      {formatMoney(row.amount)}
      <span className="sr-only">{credit ? ' credit' : ' debit'}</span>
    </span>
  )
}

/** Transactions as a table from md up and cards below. showAccount adds the account number column. */
export default function TransactionList({ transactions, showAccount = false }) {
  return (
    <>
      <ul className="space-y-2 md:hidden">
        {transactions.map((row) => (
          <li key={row.transaction_id} className="rounded-lg bg-white p-3 shadow-sm ring-1 ring-slate-200">
            <div className="flex items-baseline justify-between gap-3">
              <span className="font-medium text-slate-900">{LABELS[row.type_name] ?? row.type_name}</span>
              <SignedAmount row={row} />
            </div>
            <p className="mt-1 text-xs text-slate-500">
              {formatDateTime(row.transaction_date)} · {row.channel}
              {row.branch_name && ` · ${row.branch_name}`}
              {showAccount && ` · ${row.account_number}`}
            </p>
            {row.description && <p className="mt-1 text-sm text-slate-700">{row.description}</p>}
            <p className="mt-1 text-xs text-slate-500">Balance after: {formatMoney(row.balance_after)}</p>
          </li>
        ))}
      </ul>

      <div className="hidden overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 md:block">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
            <tr>
              <th scope="col" className="px-4 py-3">Date</th>
              {showAccount && <th scope="col" className="px-4 py-3">Account</th>}
              <th scope="col" className="px-4 py-3">Type</th>
              <th scope="col" className="px-4 py-3 text-right">Amount</th>
              <th scope="col" className="px-4 py-3 text-right">Balance after</th>
              <th scope="col" className="px-4 py-3">Channel</th>
              <th scope="col" className="px-4 py-3">Branch</th>
              <th scope="col" className="px-4 py-3">Description</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {transactions.map((row) => (
              <tr key={row.transaction_id}>
                <td className="whitespace-nowrap px-4 py-3 text-slate-700">{formatDateTime(row.transaction_date)}</td>
                {showAccount && <td className="px-4 py-3 font-mono text-xs text-slate-700">{row.account_number}</td>}
                <td className="px-4 py-3 text-slate-900">{LABELS[row.type_name] ?? row.type_name}</td>
                <td className="whitespace-nowrap px-4 py-3 text-right">
                  <SignedAmount row={row} />
                </td>
                <td className="whitespace-nowrap px-4 py-3 text-right tabular-nums text-slate-700">{formatMoney(row.balance_after)}</td>
                <td className="px-4 py-3 text-slate-700">{row.channel}</td>
                <td className="px-4 py-3 text-slate-700">{row.branch_name ?? '—'}</td>
                <td className="px-4 py-3 text-slate-700">{row.description ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  )
}
