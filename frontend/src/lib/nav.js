// The top navigation bar for each role: links ({ to, label, end? }) and dropdown groups
// ({ label, items }). search sends the nav-bar box to the existing customer search page;
// account holds the user-menu links. Only labels and paths live here: the routes and
// their guards are in App.jsx.

export const NAV = {
  admin: {
    label: 'Admin',
    items: [
      { to: '/admin', label: 'Overview', end: true },
      {
        label: 'Bank setup',
        items: [
          { to: '/admin/bank', label: 'Bank settings' },
          { to: '/admin/branches', label: 'Branches' },
          { to: '/admin/account-types', label: 'Account types' },
          { to: '/admin/card-types', label: 'Card types' },
          { to: '/admin/loan-types', label: 'Loan types' },
          { to: '/admin/departments', label: 'Departments' },
        ],
      },
      {
        label: 'People',
        items: [
          { to: '/admin/customers', label: 'Customers' },
          { to: '/admin/staff', label: 'Staff' },
          { to: '/admin/access-requests', label: 'Access requests' },
        ],
      },
      { to: '/admin/loans', label: 'Loans' },
      { to: '/admin/audit-log', label: 'Audit log' },
    ],
    search: { to: '/admin/customers', params: {} },
    account: [{ to: '/change-password', label: 'Change password' }],
  },
  staff: {
    label: 'Staff',
    items: [
      { to: '/staff', label: 'Dashboard', end: true },
      { to: '/staff/customers', label: 'Customers' },
      { to: '/staff/cards', label: 'Cards' },
      { to: '/staff/loans', label: 'Loans' },
      { to: '/staff/audit-log', label: 'Audit log' },
    ],
    // The Customers page opens on "Pending KYC"; a search from the bar looks at every customer.
    search: { to: '/staff/customers', params: { tab: 'all' } },
    account: [{ to: '/change-password', label: 'Change password' }],
  },
  customer: {
    label: 'Your banking',
    items: [
      { to: '/customer', label: 'Overview', end: true },
      { to: '/customer/accounts', label: 'Accounts' },
      { to: '/customer/transfer', label: 'Transfer' },
      { to: '/customer/cards', label: 'Cards' },
      { to: '/customer/loans', label: 'Loans' },
    ],
    search: null,
    account: [
      { to: '/customer/profile', label: 'Profile' },
      { to: '/customer/change-password', label: 'Change password' },
    ],
  },
}

/** Whether a nav link is the current page (or, without end, one of its sub-pages). */
export function isActive(item, pathname) {
  return item.end ? pathname === item.to : pathname === item.to || pathname.startsWith(`${item.to}/`)
}
