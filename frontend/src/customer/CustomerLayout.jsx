import { Outlet } from 'react-router'
import Alert from '../components/Alert.jsx'
import Loading from '../components/Loading.jsx'
import { useCustomerData } from './customerData.js'

/** The customer pages, once the overview has loaded. The section links are in the top navigation bar (lib/nav.js). */
export default function CustomerLayout() {
  const { status, error } = useCustomerData()

  if (status === 'loading') return <Loading />
  if (status === 'error') {
    return (
      <div className="mx-auto max-w-lg">
        <Alert>{error}</Alert>
      </div>
    )
  }

  return <Outlet />
}
