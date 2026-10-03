import { Navigate, Outlet, useLocation } from 'react-router'
import FullPageSpinner from '../components/FullPageSpinner.jsx'
import { dashboardPath } from '../lib/roles.js'
import { useAuth } from './useAuth.js'

/**
 * Guards a group of routes. roles: allowed role names; omit to allow any
 * logged-in user. A user in the wrong area is sent to their own dashboard.
 */
export default function ProtectedRoute({ roles }) {
  const { status, user } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return <FullPageSpinner />
  }

  if (status !== 'authenticated') {
    return <Navigate to="/login" replace state={{ from: location.pathname }} />
  }

  if (user.must_change_password && location.pathname !== '/change-password') {
    return <Navigate to="/change-password" replace />
  }

  if (roles && !roles.includes(user.role)) {
    return <Navigate to={dashboardPath(user)} replace />
  }

  return <Outlet />
}
