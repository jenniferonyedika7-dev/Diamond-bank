import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import Alert from '../../components/Alert.jsx'
import Badge from '../../components/Badge.jsx'
import EmptyState from '../../components/EmptyState.jsx'
import FormField from '../../components/FormField.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Pagination from '../../components/Pagination.jsx'
import { LoanStatusBadge } from '../../components/StatusBadges.jsx'
import { formatDate } from '../../lib/format.js'
import { PLAN_LABELS, STATUS_TABS } from '../../lib/loans.js'
import { formatMoney } from '../../lib/money.js'
import { useApiList, useDebounced } from '../../lib/useApiList.js'

const DESCRIPTIONS = {
  staff: 'Loan applications for accounts at your branch. Record the four checks, then approve or reject.',
  admin: 'Loans at every branch. Staff have approved the ones awaiting you; your approval pays the loan out.',
}

/** The staff (/staff/loans, their branch) and admin (/admin/loans, every branch) loan lists. */
export default function LoanReviewList({ area, defaultTab, showBranch = false }) {
  const [searchParams, setSearchParams] = useSearchParams()
  const tab = STATUS_TABS.find((t) => t.id === (searchParams.get('tab') ?? defaultTab)) ?? STATUS_TABS[0]
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const debouncedSearch = useDebounced(search.trim())
  const { items, pagination, extra, loading, error } = useApiList(`/api/v1/${area}/loans`, {
    status: tab.status,
    search: debouncedSearch || undefined,
    page,
  })

  function selectTab(id) {
    setSearchParams({ tab: id })
    setPage(1)
  }

  return (
    <section>
      <PageHeader title="Loans" description={DESCRIPTIONS[area]} />

      <div role="tablist" aria-label="Loans by status" className="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        {STATUS_TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`tab-${t.id}`}
            aria-selected={t.id === tab.id}
            aria-controls="loans-panel"
            onClick={() => selectTab(t.id)}
            className={`-mb-px whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-navy-600 ${
              t.id === tab.id ? 'border-navy-900 text-navy-900' : 'border-transparent text-slate-600 hover:text-navy-900'
            }`}
          >
            {t.label}
            {extra.counts && <span className="ml-1.5 text-xs text-slate-500">({extra.counts[t.status] ?? 0})</span>}
          </button>
        ))}
      </div>

      <div id="loans-panel" role="tabpanel" aria-labelledby={`tab-${tab.id}`}>
        <div className="mb-4 max-w-sm">
          <FormField
            id="loan-search"
            label="Search by customer name, account number or loan number"
            type="search"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
          />
        </div>

        {loading ? (
          <Loading />
        ) : error ? (
          <Alert>{error}</Alert>
        ) : items.length === 0 ? (
          <EmptyState title={debouncedSearch ? `No loans match "${debouncedSearch}".` : tab.empty} />
        ) : (
          <>
            <ul className="space-y-3">
              {items.map((loan) => (
                <LoanRow key={loan.loan_id} loan={loan} area={area} showBranch={showBranch} />
              ))}
            </ul>
            <Pagination pagination={pagination} onPageChange={setPage} />
          </>
        )}
      </div>
    </section>
  )
}

function LoanRow({ loan, area, showBranch }) {
  const { affordability } = loan
  return (
    <li className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200 sm:p-5">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <p className="flex flex-wrap items-center gap-2 font-medium text-slate-900">
            {area === 'staff' ? (
              <Link
                to={`/staff/customers/${loan.customer.customer_id}`}
                className="text-navy-800 underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-navy-600"
              >
                {loan.customer.name}
              </Link>
            ) : (
              <span>{loan.customer.name}</span>
            )}
            <LoanStatusBadge status={loan.status} />
          </p>
          <p className="mt-1 text-sm text-slate-700">
            <span className="font-medium tabular-nums">{formatMoney(loan.amount)}</span> · {loan.loan_type.type_name} ·{' '}
            {PLAN_LABELS[loan.repayment_plan]} · {loan.term_months} {Number(loan.term_months) === 1 ? 'month' : 'months'}
          </p>
          <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-600">
            <span>
              Affordability: <span className="tabular-nums">{affordability.percent_of_income}%</span> of income
            </span>
            {affordability.above_warning && <Badge tone="warning">Above {affordability.warning_percent}%</Badge>}
          </p>
          <p className="mt-1 text-xs text-slate-500">
            Loan #{loan.loan_id} · applied {formatDate(loan.application_date)} · TIN <span className="font-mono">{loan.tin}</span>
            {showBranch && ` · ${loan.branch.branch_name}`}
          </p>
        </div>
        <Link
          to={`/${area}/loans/${loan.loan_id}`}
          className="inline-flex shrink-0 items-center justify-center rounded-lg bg-white px-4 py-2 text-sm font-semibold text-navy-900 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-navy-600"
        >
          Review<span className="sr-only"> loan #{loan.loan_id} for {loan.customer.name}</span>
        </Link>
      </div>
    </li>
  )
}
