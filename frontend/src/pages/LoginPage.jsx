import { useEffect, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth.js'
import Alert from '../components/Alert.jsx'
import AuthLayout from '../components/AuthLayout.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import { getErrorMessage, getFieldErrors } from '../lib/api.js'
import { dashboardPath } from '../lib/roles.js'

export default function LoginPage() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const flash = location.state?.flash

  const [form, setForm] = useState({ user_name: '', password: '' })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const [retryIn, setRetryIn] = useState(0)

  // 429: the backend puts the wait only in the message ("Try again in N seconds.");
  // its Retry-After header isn't exposed via CORS. Count down and keep the button disabled.
  useEffect(() => {
    if (retryIn <= 0) return undefined
    const timer = setTimeout(() => setRetryIn((s) => s - 1), 1000)
    return () => clearTimeout(timer)
  }, [retryIn])

  function update(event) {
    setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})

    try {
      const user = await login(form.user_name, form.password)
      navigate(dashboardPath(user), { replace: true })
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setForm((prev) => ({ ...prev, password: '' }))
      if (err.response?.status === 429) {
        const seconds = Number(getErrorMessage(err).match(/(\d+) seconds?/)?.[1])
        if (seconds > 0) setRetryIn(seconds)
      }
      setSubmitting(false)
    }
  }

  return (
    <AuthLayout title="Sign in" subtitle="Welcome back. Sign in to your Diamond Bank account.">
      <form onSubmit={handleSubmit} noValidate className="space-y-5">
        {flash && !error && <Alert tone="success">{flash}</Alert>}
        <Alert>{error}</Alert>
        {retryIn > 0 && (
          <p className="text-sm text-slate-600" aria-live="polite">
            You can try again in {retryIn} {retryIn === 1 ? 'second' : 'seconds'}.
          </p>
        )}

        <FormField
          id="user_name"
          label="Username"
          autoComplete="username"
          required
          value={form.user_name}
          onChange={update}
          error={fieldErrors.user_name}
        />
        <FormField
          id="password"
          label="Password"
          type="password"
          autoComplete="current-password"
          required
          value={form.password}
          onChange={update}
          error={fieldErrors.password}
        />

        <Button type="submit" className="w-full" loading={submitting} loadingText="Signing in…" disabled={retryIn > 0}>
          Sign in
        </Button>
      </form>

      <div className="mt-6 space-y-1 border-t border-slate-200 pt-5 text-sm text-slate-600">
        <p>
          New customer?{' '}
          <Link to="/register" className="font-medium text-navy-700 underline-offset-2 hover:underline">
            Open an account
          </Link>
        </p>
        <p>
          Bank employee?{' '}
          <Link to="/register/staff" className="font-medium text-navy-700 underline-offset-2 hover:underline">
            Register as staff
          </Link>
        </p>
      </div>
    </AuthLayout>
  )
}
