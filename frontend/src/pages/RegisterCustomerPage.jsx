import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import Alert from '../components/Alert.jsx'
import AuthLayout from '../components/AuthLayout.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import PasswordRules from '../components/PasswordRules.jsx'
import Spinner from '../components/Spinner.jsx'
import { api, ensureCsrf, getErrorMessage, getFieldErrors } from '../lib/api.js'

const EMPTY = {
  first_name: '',
  last_name: '',
  date_of_birth: '',
  gender: '',
  national_id: '',
  phone: '',
  email: '',
  branch_id: '',
  user_name: '',
  password: '',
  password_confirmation: '',
}

// The backend requires customers to be at least 18.
function latestBirthDate() {
  const date = new Date()
  date.setFullYear(date.getFullYear() - 18)
  return date.toISOString().slice(0, 10)
}

export default function RegisterCustomerPage() {
  const navigate = useNavigate()
  const [branches, setBranches] = useState(null) // null while loading
  const [branchError, setBranchError] = useState('')
  const [form, setForm] = useState(EMPTY)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/branches')
      .then(({ data }) => !ignore && setBranches(data.data ?? []))
      .catch((err) => !ignore && setBranchError(getErrorMessage(err, 'Could not load branches.')))
    return () => {
      ignore = true
    }
  }, [])

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
      const { data } = await api.post('/api/v1/auth/register/customer', {
        ...form,
        branch_id: form.branch_id === '' ? '' : Number(form.branch_id),
      })
      navigate('/login', { replace: true, state: { flash: data.message } })
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  const loginLink = (
    <p className="mt-6 border-t border-slate-200 pt-5 text-sm text-slate-600">
      Already have an account?{' '}
      <Link to="/login" className="font-medium text-navy-700 underline-offset-2 hover:underline">
        Sign in
      </Link>
    </p>
  )

  if (branchError) {
    return (
      <AuthLayout title="Open an account">
        <Alert>{branchError}</Alert>
        {loginLink}
      </AuthLayout>
    )
  }

  if (branches === null) {
    return (
      <AuthLayout title="Open an account">
        <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
          <Spinner /> Loading…
        </div>
      </AuthLayout>
    )
  }

  if (branches.length === 0) {
    return (
      <AuthLayout title="Open an account">
        <Alert tone="info">Registration is not open yet. The bank has no branches set up.</Alert>
        {loginLink}
      </AuthLayout>
    )
  }

  const field = (id) => ({ id, value: form[id], onChange: update, error: fieldErrors[id], required: true })

  return (
    <AuthLayout title="Open an account" subtitle="All fields are required." wide>
      <form onSubmit={handleSubmit} noValidate className="space-y-6">
        <Alert>{error}</Alert>

        <fieldset className="space-y-4">
          <legend className="text-sm font-semibold uppercase tracking-wide text-navy-700">Personal details</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="First name" autoComplete="given-name" maxLength={100} {...field('first_name')} />
            <FormField label="Last name" autoComplete="family-name" maxLength={100} {...field('last_name')} />
            <FormField
              label="Date of birth"
              type="date"
              autoComplete="bday"
              max={latestBirthDate()}
              hint="You must be at least 18."
              {...field('date_of_birth')}
            />
            <FormField label="Gender" {...field('gender')}>
              <option value="">Select…</option>
              <option value="F">Female</option>
              <option value="M">Male</option>
            </FormField>
            <FormField label="National ID number" maxLength={30} {...field('national_id')} />
          </div>
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="text-sm font-semibold uppercase tracking-wide text-navy-700">Contact</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Phone number" type="tel" autoComplete="tel" maxLength={20} {...field('phone')} />
            <FormField label="Email address" type="email" autoComplete="email" maxLength={150} {...field('email')} />
            <FormField label="Home branch" className="sm:col-span-2" {...field('branch_id')}>
              <option value="">Select a branch…</option>
              {branches.map((branch) => (
                <option key={branch.branch_id} value={branch.branch_id}>
                  {branch.branch_name}
                </option>
              ))}
            </FormField>
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
            <FormField
              label="Confirm password"
              type="password"
              autoComplete="new-password"
              {...field('password_confirmation')}
            />
          </div>
        </fieldset>

        <Button type="submit" className="w-full" loading={submitting} loadingText="Creating account…">
          Create account
        </Button>
      </form>
      {loginLink}
    </AuthLayout>
  )
}
