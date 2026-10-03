import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import { AuthProvider } from './auth/AuthContext.jsx'
import { useAuth } from './auth/useAuth.js'
import ProtectedRoute from './auth/ProtectedRoute.jsx'
import PublicOnlyRoute from './auth/PublicOnlyRoute.jsx'
import AppLayout from './components/AppLayout.jsx'
import FullPageSpinner from './components/FullPageSpinner.jsx'
import { dashboardPath } from './lib/roles.js'
import ChangePasswordPage from './pages/ChangePasswordPage.jsx'
import DashboardPage from './pages/DashboardPage.jsx'
import LoginPage from './pages/LoginPage.jsx'
import RegisterCustomerPage from './pages/RegisterCustomerPage.jsx'
import RegisterStaffPage from './pages/RegisterStaffPage.jsx'

/** "/" and unknown paths: the user's dashboard, or /login. */
function HomeRedirect() {
  const { status, user } = useAuth()
  if (status === 'loading') return <FullPageSpinner />
  return <Navigate to={dashboardPath(user)} replace />
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route element={<PublicOnlyRoute />}>
            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterCustomerPage />} />
            <Route path="/register/staff" element={<RegisterStaffPage />} />
          </Route>

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/change-password" element={<ChangePasswordPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={['admin']} />}>
            <Route element={<AppLayout />}>
              <Route path="/admin" element={<DashboardPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={['staff']} />}>
            <Route element={<AppLayout />}>
              <Route path="/staff" element={<DashboardPage />} />
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={['customer']} />}>
            <Route element={<AppLayout />}>
              <Route path="/customer" element={<DashboardPage />} />
            </Route>
          </Route>

          <Route path="*" element={<HomeRedirect />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
