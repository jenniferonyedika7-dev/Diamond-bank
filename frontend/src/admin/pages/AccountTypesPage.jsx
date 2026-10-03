import { formatMoney } from '../../lib/money.js'
import { formatPercent } from '../../lib/format.js'
import ResourcePage from '../ResourcePage.jsx'

const config = {
  endpoint: '/api/v1/admin/account-types',
  idKey: 'account_type_id',
  title: 'Account types',
  description: 'The kinds of account customers can open, with their interest rate and minimum balance.',
  singular: 'account type',
  emptyText: 'No account types yet. Add your first account type.',
  columns: [
    { key: 'type_name', label: 'Name' },
    { key: 'interest_rate', label: 'Interest rate', align: 'right', render: (row) => formatPercent(row.interest_rate) },
    { key: 'minimum_balance', label: 'Minimum balance', align: 'right', render: (row) => formatMoney(row.minimum_balance) },
    { key: 'accounts_count', label: 'Accounts', align: 'right' },
  ],
  fields: [
    { name: 'type_name', label: 'Name', maxLength: 50, hint: 'For example, SAVINGS.' },
    { name: 'interest_rate', label: 'Interest rate (% per year)', type: 'number', inputMode: 'decimal', min: 0, max: 100, step: '0.01', hint: '0 to 100, up to 2 decimal places.' },
    { name: 'minimum_balance', label: 'Minimum balance (GMD)', type: 'number', inputMode: 'decimal', min: 0, step: '0.01' },
  ],
}

export default function AccountTypesPage() {
  return <ResourcePage config={config} />
}
