import { Outlet } from 'react-router'
import Alert from '../components/Alert.jsx'
import Spinner from '../components/Spinner.jsx'
import { useStaffBranch } from './staffBranch.js'

/**
 * Staff work at their current branch; without one the API refuses everything, so say so up front.
 * The section links are in the top navigation bar (lib/nav.js).
 */
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

  return <Outlet />
}
