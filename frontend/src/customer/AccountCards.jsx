import { Link } from 'react-router'
import { AccountStatusBadge } from '../components/StatusBadges.jsx'
import { formatMoney } from '../lib/money.js'

export default function AccountCards({ accounts }) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2">
      {accounts.map((a) => (
        <li key={a.account_number}>
          <Link
            to={`/customer/accounts/${a.account_number}`}
            className="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 hover:ring-navy-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600"
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-sm font-medium text-slate-700">{a.type_name}</span>
              <AccountStatusBadge status={a.status} />
            </div>
            <p className="mt-3 text-3xl font-semibold tabular-nums tracking-tight text-navy-900">{formatMoney(a.balance)}</p>
            <p className="mt-1 font-mono text-sm text-slate-500">{a.account_number}</p>
          </Link>
        </li>
      ))}
    </ul>
  )
}
