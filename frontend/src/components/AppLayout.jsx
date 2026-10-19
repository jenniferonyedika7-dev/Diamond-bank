import { useState } from 'react'
import { Outlet } from 'react-router'
import { useAuth } from '../auth/useAuth.js'
import TopNav from './TopNav.jsx'

/**
 * Shell for every logged-in page: the top navigation bar (sections, user menu, Log out).
 * headerExtra: optional line about the user (the staff area shows the branch).
 */
export default function AppLayout({ headerExtra }) {
  const { user, logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)

  async function handleLogout() {
    setSigningOut(true)
    await logout()
  }

  return (
    <div className="flex min-h-dvh flex-col">
      <TopNav user={user} extra={headerExtra} onLogout={handleLogout} signingOut={signingOut} />
      <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:py-8">
        <Outlet />
      </main>
    </div>
  )
}
