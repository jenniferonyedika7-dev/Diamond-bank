import { createContext, useContext } from 'react'

export const AuthContext = createContext(null)

/** { status, user, setUser, login, logout, refresh } from <AuthProvider>. */
export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used inside <AuthProvider>.')
  }
  return context
}
