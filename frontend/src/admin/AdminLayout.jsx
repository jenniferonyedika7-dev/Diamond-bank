import SideNavLayout from '../components/SideNavLayout.jsx'

const ADMIN_NAV = [
  { to: '/admin', label: 'Overview', end: true },
  { to: '/admin/bank', label: 'Bank settings' },
  { to: '/admin/branches', label: 'Branches' },
  { to: '/admin/account-types', label: 'Account types' },
  { to: '/admin/card-types', label: 'Card types' },
  { to: '/admin/loan-types', label: 'Loan types' },
  { to: '/admin/departments', label: 'Departments' },
  { to: '/admin/customers', label: 'Customers' },
  { to: '/admin/loans', label: 'Loans' },
  { to: '/admin/staff', label: 'Staff' },
  { to: '/admin/audit-log', label: 'Audit log' },
]

export default function AdminLayout() {
  return <SideNavLayout items={ADMIN_NAV} label="Admin" />
}
