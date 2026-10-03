import axios from 'axios'

/**
 * Sanctum SPA cookie auth: the session lives in an httpOnly cookie, and axios
 * copies the XSRF-TOKEN cookie into the X-XSRF-TOKEN header on every request.
 * axios reads the cookie fresh each time, so the token rotation Laravel does on
 * login, change-password and logout needs no special handling here.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  withCredentials: true,
  withXSRFToken: true, // axios 1.6+: needed to send X-XSRF-TOKEN cross-origin (5173 -> 8000)
  headers: { Accept: 'application/json' },
})

/** Sets the XSRF-TOKEN cookie. Call before login, register and change-password. */
export function ensureCsrf() {
  return api.get('/sanctum/csrf-cookie')
}

// AuthContext registers these; they only update auth state; the route guards redirect.
let handlers = {
  onUnauthenticated: () => {},
  onMustChangePassword: () => {},
}

export function setAuthHandlers(next) {
  handlers = { ...handlers, ...next }
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const { config, response } = error
    if (!response || !config) {
      return Promise.reject(error)
    }

    // CSRF token missing or expired: fetch a new one and retry once.
    if (response.status === 419 && !config._csrfRetried) {
      config._csrfRetried = true
      await ensureCsrf()
      return api(config)
    }

    // A 401 from login means wrong credentials, not an expired session.
    if (response.status === 401 && !config.url?.endsWith('/auth/login')) {
      handlers.onUnauthenticated()
    }

    if (response.status === 403) {
      if (response.data?.data?.must_change_password === true) {
        handlers.onMustChangePassword()
      } else if (response.data?.message === 'Your account is not active.') {
        // Blocked mid-session; the backend has already logged the user out.
        handlers.onUnauthenticated()
      }
    }

    return Promise.reject(error)
  },
)

/** The backend's message: the { success, message, data } envelope, or a 422's top-level message. */
export function getErrorMessage(error, fallback = 'Something went wrong. Please try again.') {
  if (!error?.response) {
    return "Can't reach the server. Check your connection and try again."
  }
  return error.response.data?.message || fallback
}

/** 422 errors as { field: firstMessage }. Empty object for any other error. */
export function getFieldErrors(error) {
  if (error?.response?.status !== 422) {
    return {}
  }
  const errors = error.response.data?.errors ?? {}
  return Object.fromEntries(Object.entries(errors).map(([field, messages]) => [field, messages[0]]))
}
