import { Link } from 'react-router'
import { formatDate, todayIso } from '../../lib/format.js'
import ResourcePage from '../ResourcePage.jsx'

const config = {
  endpoint: '/api/v1/admin/branches',
  idKey: 'branch_id',
  title: 'Branches',
  description: 'Every customer, account and staff member belongs to a branch.',
  singular: 'branch',
  emptyText: 'No branches yet. Add your first branch.',
  paginated: true,
  searchable: true,
  searchLabel: 'Search by name or code',
  columns: [
    { key: 'branch_name', label: 'Name' },
    { key: 'branch_code', label: 'Code' },
    { key: 'phone', label: 'Phone' },
    { key: 'opened_date', label: 'Opened', render: (row) => formatDate(row.opened_date) },
    { key: 'customers_count', label: 'Customers', align: 'right' },
    { key: 'staff_count', label: 'Staff', align: 'right' },
  ],
  fields: [
    { name: 'branch_name', label: 'Branch name', maxLength: 100 },
    { name: 'branch_code', label: 'Branch code', maxLength: 20, hint: 'Letters, digits and hyphens. Saved in capitals, e.g. BJL001.', className: '[&_input]:uppercase' },
    { name: 'address', label: 'Address', maxLength: 255 },
    { name: 'phone', label: 'Phone number', type: 'tel', maxLength: 20 },
    { name: 'opened_date', label: 'Opened on', type: 'date', max: todayIso() },
  ],
  // POST before the bank exists: 422 "Set up the bank details first." with errors.bank.
  formErrorExtra: (fieldErrors) =>
    fieldErrors.bank && (
      <>
        {' '}
        <Link to="/admin/bank" className="font-semibold underline">
          Go to Bank settings
        </Link>
      </>
    ),
}

export default function BranchesPage() {
  return <ResourcePage config={config} />
}
