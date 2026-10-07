import { formatPercent } from '../../lib/format.js'
import ResourcePage from '../ResourcePage.jsx'

const config = {
  endpoint: '/api/v1/admin/loan-types',
  idKey: 'loan_type_id',
  title: 'Loan types',
  description: 'The kinds of loan customers can apply for, with their annual interest rate.',
  singular: 'loan type',
  emptyText: 'No loan types yet. Add one so customers can apply for loans.',
  columns: [
    { key: 'type_name', label: 'Name' },
    { key: 'interest_rate', label: 'Annual rate', align: 'right', render: (row) => formatPercent(row.interest_rate) },
    { key: 'loans_count', label: 'Loans', align: 'right' },
  ],
  fields: [
    { name: 'type_name', label: 'Name', maxLength: 50, hint: 'For example Personal or Business. Names are saved in Title Case.' },
    {
      name: 'interest_rate',
      label: 'Annual interest rate (%)',
      type: 'number',
      inputMode: 'decimal',
      min: 0.01,
      max: 99.99,
      step: '0.01',
      hint: 'More than 0 and below 100, up to 2 decimal places. A new rate applies to new applications only.',
    },
  ],
}

export default function LoanTypesPage() {
  return <ResourcePage config={config} />
}
