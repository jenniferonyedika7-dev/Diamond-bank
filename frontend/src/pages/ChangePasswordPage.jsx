import { useState } from 'react'
import { useNavigate } from 'react-router'
import { useAuth } from '../auth/useAuth.js'
import Alert from '../components/Alert.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import PasswordRules from '../components/PasswordRules.jsx'
import { api, ensureCsrf, getErrorMessage, getFieldErrors } from '../lib/api.js'
import { dashboardPath } from '../lib/roles.js'

const EMPTY = { current_password: '', password: '', password_confirmation: '' }

export default function ChangePasswordPage() {
  const { user, setUser, refresh } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState(EMPTY)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  function update(event) {
    setForm((prev) => ({ ...prev, [event.target.name]: event.target.value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})

    try {
      await ensureCsrf()
      const { data } = await api.post('/api/v1/auth/change-password', form)
      // The response already has the updated user; /auth/me then confirms the
      // regenerated session works. If it doesn't, refresh() logs out locally.
      setUser(data.data)
      const me = (await refresh()) ?? data.data
      navigate(dashboardPath(me), { replace: true })
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  const field = (id) => ({ id, value: form[id], onChange: update, error: fieldErrors[id], required: true })

  return (
    <div className="mx-auto max-w-md">
      <div className="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 sm:p-8">
        <h1 className="text-2xl font-semibold text-navy-900">Change your password</h1>
        {user?.must_change_password && (
          <Alert tone="info" className="mt-4">
            You must change your password before continuing.
          </Alert>
        )}

        <form onSubmit={handleSubmit} noValidate className="mt-6 space-y-5">
          <Alert>{error}</Alert>
          <FormField label="Current password" type="password" autoComplete="current-password" {...field('current_password')} />
          <PasswordRules id="password-rules" extra={['Different from your current password']} />
          <FormField
            label="New password"
            type="password"
            autoComplete="new-password"
            describedBy="password-rules"
            {...field('password')}
          />
          <FormField label="Confirm new password" type="password" autoComplete="new-password" {...field('password_confirmation')} />
          <Button type="submit" className="w-full" loading={submitting} loadingText="Saving…">
            Change password
          </Button>
        </form>
      </div>
    </div>
  )
}
