import { Navigate, Outlet } from 'react-router'
import FullPageSpinner from '../components/FullPageSpinner.jsx'
import { dashboardPath } from '../lib/roles.js'
import { useAuth } from './useAuth.js'

/** Login and registration pages: a logged-in user is sent to their dashboard. */
export default function PublicOnlyRoute() {
  const { status, user } = useAuth()

  if (status === 'loading') {
    return <FullPageSpinner />
  }

  if (status === 'authenticated') {
    return <Navigate to={dashboardPath(user)} replace />
  }

  return <Outlet />
}
