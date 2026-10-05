import Alert from '../components/Alert.jsx'
import Loading from '../components/Loading.jsx'
import SideNavLayout from '../components/SideNavLayout.jsx'
import { useCustomerData } from './customerData.js'

const CUSTOMER_NAV = [
  { to: '/customer', label: 'Overview', end: true },
  { to: '/customer/accounts', label: 'Accounts' },
  { to: '/customer/transfer', label: 'Transfer' },
  { to: '/customer/cards', label: 'Cards' },
  { to: '/customer/profile', label: 'Profile' },
  { to: '/customer/change-password', label: 'Change password' },
]

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

  return <SideNavLayout items={CUSTOMER_NAV} label="Your banking" />
}
