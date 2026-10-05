import Alert from '../components/Alert.jsx'
import SideNavLayout from '../components/SideNavLayout.jsx'
import Spinner from '../components/Spinner.jsx'
import { useStaffBranch } from './staffBranch.js'

const STAFF_NAV = [
  { to: '/staff', label: 'Dashboard', end: true },
  { to: '/staff/customers', label: 'Customers' },
  { to: '/staff/cards', label: 'Cards' },
  { to: '/staff/audit-log', label: 'Audit log' },
]

/** Staff work at their current branch; without one the API refuses everything, so say so up front. */
export default function StaffLayout() {
  const { status, error } = useStaffBranch()

  if (status === 'loading') {
    return (
      <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
        <Spinner /> Loading…
      </div>
    )
  }
  if (status === 'error') {
    return (
      <div className="mx-auto max-w-lg">
        <Alert>{error}</Alert>
      </div>
    )
  }

  return <SideNavLayout items={STAFF_NAV} label="Staff" />
}
