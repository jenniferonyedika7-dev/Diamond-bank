import { BrowserRouter, Navigate, Route, Routes } from 'react-router'
import AdminLayout from './admin/AdminLayout.jsx'
import AccountTypesPage from './admin/pages/AccountTypesPage.jsx'
import BankSettingsPage from './admin/pages/BankSettingsPage.jsx'
import BranchesPage from './admin/pages/BranchesPage.jsx'
import CardTypesPage from './admin/pages/CardTypesPage.jsx'
import DepartmentsPage from './admin/pages/DepartmentsPage.jsx'
import OverviewPage from './admin/pages/OverviewPage.jsx'
import StaffPage from './admin/pages/StaffPage.jsx'
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
import AuditLogPage from './shared/AuditLogPage.jsx'
import AccountDetailPage from './staff/pages/AccountDetailPage.jsx'
import CustomerDetailPage from './staff/pages/CustomerDetailPage.jsx'
import CustomersPage from './staff/pages/CustomersPage.jsx'
import StaffDashboardPage from './staff/pages/DashboardPage.jsx'
import StaffLayout from './staff/StaffLayout.jsx'
import StaffShell from './staff/StaffShell.jsx'

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
              <Route path="/admin" element={<AdminLayout />}>
                <Route index element={<OverviewPage />} />
                <Route path="bank" element={<BankSettingsPage />} />
                <Route path="branches" element={<BranchesPage />} />
                <Route path="account-types" element={<AccountTypesPage />} />
                <Route path="card-types" element={<CardTypesPage />} />
                <Route path="departments" element={<DepartmentsPage />} />
                <Route path="staff" element={<StaffPage />} />
                <Route path="audit-log" element={<AuditLogPage />} />
              </Route>
            </Route>
          </Route>
          <Route element={<ProtectedRoute roles={['staff']} />}>
            <Route element={<StaffShell />}>
              <Route path="/staff" element={<StaffLayout />}>
                <Route index element={<StaffDashboardPage />} />
                <Route path="customers" element={<CustomersPage />} />
                <Route path="customers/:id" element={<CustomerDetailPage />} />
                <Route path="accounts/:accountNumber" element={<AccountDetailPage />} />
                <Route path="audit-log" element={<AuditLogPage />} />
              </Route>
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
