import { useState } from 'react'
import { Link } from 'react-router'
import Alert from '../components/Alert.jsx'
import AuthLayout from '../components/AuthLayout.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import PasswordRules from '../components/PasswordRules.jsx'
import { api, ensureCsrf, getErrorMessage, getFieldErrors } from '../lib/api.js'

const EMPTY = {
  full_name: '',
  national_id: '',
  position: '',
  phone: '',
  email: '',
  user_name: '',
  password: '',
  password_confirmation: '',
}

export default function RegisterStaffPage() {
  const [form, setForm] = useState(EMPTY)
  const [submitting, setSubmitting] = useState(false)
  const [registered, setRegistered] = useState(false)
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
      await api.post('/api/v1/auth/register/staff', form)
      setRegistered(true)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
    } finally {
      setSubmitting(false)
    }
  }

  const backToLogin = (
    <Link to="/login" className="font-medium text-navy-700 underline-offset-2 hover:underline">
      Back to sign in
    </Link>
  )

  if (registered) {
    return (
      <AuthLayout title="Registration received">
        <Alert tone="success">Your account is awaiting admin approval.</Alert>
        <p className="mt-4 text-sm text-slate-600">You can sign in once an administrator has approved your account.</p>
        <p className="mt-6 text-sm">{backToLogin}</p>
      </AuthLayout>
    )
  }

  const field = (id) => ({ id, value: form[id], onChange: update, error: fieldErrors[id], required: true })

  return (
    <AuthLayout
      title="Staff registration"
      subtitle="For Diamond Bank employees. An administrator must approve your account before you can sign in."
      wide
    >
      <form onSubmit={handleSubmit} noValidate className="space-y-6">
        <Alert>{error}</Alert>

        <fieldset className="space-y-4">
          <legend className="text-sm font-semibold uppercase tracking-wide text-navy-700">Employee details</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Full name" autoComplete="name" maxLength={150} className="sm:col-span-2" {...field('full_name')} />
            <FormField label="National ID number" maxLength={30} {...field('national_id')} />
            <FormField label="Position" autoComplete="organization-title" maxLength={100} hint="For example, Teller." {...field('position')} />
            <FormField label="Phone number" type="tel" autoComplete="tel" maxLength={20} {...field('phone')} />
            <FormField label="Work email" type="email" autoComplete="email" maxLength={150} {...field('email')} />
          </div>
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="text-sm font-semibold uppercase tracking-wide text-navy-700">Sign-in details</legend>
          <FormField label="Username" autoComplete="username" maxLength={50} {...field('user_name')} />
          <PasswordRules id="password-rules" />
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField
              label="Password"
              type="password"
              autoComplete="new-password"
              describedBy="password-rules"
              {...field('password')}
            />
            <FormField label="Confirm password" type="password" autoComplete="new-password" {...field('password_confirmation')} />
          </div>
        </fieldset>

        <Button type="submit" className="w-full" loading={submitting} loadingText="Submitting…">
          Submit registration
        </Button>
      </form>
      <p className="mt-6 border-t border-slate-200 pt-5 text-sm text-slate-600">{backToLogin}</p>
    </AuthLayout>
  )
}
