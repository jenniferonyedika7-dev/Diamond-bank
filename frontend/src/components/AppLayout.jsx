import { useState } from 'react'
import { Outlet } from 'react-router'
import { useAuth } from '../auth/useAuth.js'
import { displayName, ROLE_LABEL } from '../lib/roles.js'
import Brand from './Brand.jsx'
import Button from './Button.jsx'

/** Shell for every logged-in page: brand, who is signed in, and Logout. */
export default function AppLayout() {
  const { user, logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)
  const name = displayName(user)
  const role = ROLE_LABEL[user?.role]

  async function handleLogout() {
    setSigningOut(true)
    await logout()
  }

  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-navy-900 text-white">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <Brand className="text-lg" />
          <div className="flex items-center gap-3">
            {(name || role) && (
              <div className="text-right text-sm leading-tight">
                {name && <p className="font-medium">{name}</p>}
                {role && <p className="text-xs text-navy-100">{role}</p>}
              </div>
            )}
            <Button
              variant="secondary"
              onClick={handleLogout}
              loading={signingOut}
              loadingText="Signing out…"
              className="py-2"
            >
              Log out
            </Button>
          </div>
        </div>
      </header>
      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
        <Outlet />
      </main>
    </div>
  )
}
