import { useCallback, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { api, ensureCsrf, setAuthHandlers } from '../lib/api.js'
import { AuthContext } from './useAuth.js'

/**
 * Asks /auth/me who is logged in. Resolves to the next auth state:
 * the user, the must-change-password flag (from the interceptor), or guest.
 */
async function loadMe() {
  try {
    const { data } = await api.get('/api/v1/auth/me')
    return { status: 'authenticated', user: data.data }
  } catch (error) {
    if (error.response?.data?.data?.must_change_password === true) {
      return null // already applied by the interceptor's onMustChangePassword
    }
    return { status: 'guest', user: null }
  }
}

/**
 * The user lives in memory only (never localStorage/sessionStorage); the
 * session cookie is the source of truth, and /auth/me is asked on startup.
 *
 * status: 'loading' until /auth/me answers, then 'authenticated' or 'guest'.
 */
export function AuthProvider({ children }) {
  const navigate = useNavigate()
  const [state, setState] = useState({ status: 'loading', user: null })

  const setGuest = useCallback(() => setState({ status: 'guest', user: null }), [])
  const setUser = useCallback((user) => setState({ status: 'authenticated', user }), [])

  // /auth/me sits behind the password.changed middleware, so a user who must
  // change their password gets a 403 instead of their details. Keep whatever
  // we already know (from the login response) and flag the change.
  const markMustChangePassword = useCallback(() => {
    setState((prev) => ({
      status: 'authenticated',
      user: { ...(prev.user ?? {}), must_change_password: true },
    }))
  }, [])

  const fetchMe = useCallback(async () => {
    const next = await loadMe()
    if (next) setState(next)
    return next?.user ?? null
  }, [])

  useEffect(() => {
    setAuthHandlers({
      onUnauthenticated: setGuest,
      onMustChangePassword: markMustChangePassword,
    })
    loadMe().then((next) => next && setState(next))
  }, [markMustChangePassword, setGuest])

  const login = useCallback(
    async (userName, password) => {
      await ensureCsrf()
      const { data } = await api.post('/api/v1/auth/login', { user_name: userName, password })
      setUser(data.data)
      return data.data
    },
    [setUser],
  )

  const logout = useCallback(async () => {
    try {
      await api.post('/api/v1/auth/logout')
    } catch {
      // Already logged out on the server (expired session etc.): clear locally anyway.
    }
    setGuest()
    navigate('/login', { replace: true })
  }, [navigate, setGuest])

  const value = useMemo(
    () => ({
      status: state.status,
      user: state.user,
      setUser,
      login,
      logout,
      refresh: fetchMe,
    }),
    [state, setUser, login, logout, fetchMe],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
