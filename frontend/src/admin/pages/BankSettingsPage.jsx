import { useEffect, useState } from 'react'
import Alert from '../../components/Alert.jsx'
import Button from '../../components/Button.jsx'
import FormField from '../../components/FormField.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import Spinner from '../../components/Spinner.jsx'
import { api, getErrorMessage, getFieldErrors } from '../../lib/api.js'
import { todayIso } from '../../lib/format.js'

const EMPTY = { bank_name: '', swift_code: '', established_date: '' }

/** The single bank row: PUT creates it the first time and updates it afterwards. */
export default function BankSettingsPage() {
  const [form, setForm] = useState(null) // null while loading
  const [exists, setExists] = useState(false)
  const [loadError, setLoadError] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/admin/bank')
      .then(({ data }) => {
        if (ignore) return
        setExists(data.data !== null)
        setForm(data.data ? { bank_name: data.data.bank_name, swift_code: data.data.swift_code, established_date: data.data.established_date } : EMPTY)
      })
      .catch((err) => !ignore && setLoadError(getErrorMessage(err)))
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
    setSuccess('')
    setFieldErrors({})
    try {
      const { data } = await api.put('/api/v1/admin/bank', form)
      setExists(true)
      setForm({ bank_name: data.data.bank_name, swift_code: data.data.swift_code, established_date: data.data.established_date })
      setSuccess(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
    } finally {
      setSubmitting(false)
    }
  }

  if (loadError) return <Alert>{loadError}</Alert>
  if (!form) {
    return (
      <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
        <Spinner /> Loading…
      </div>
    )
  }

  const field = (id) => ({ id, value: form[id], onChange: update, error: fieldErrors[id], required: true })

  return (
    <section className="max-w-xl">
      <PageHeader
        title={exists ? 'Bank details' : 'Set up your bank'}
        description={exists ? 'The bank this system runs. There is only ever one.' : 'Enter the bank’s details. You can change them later.'}
      />
      <form onSubmit={handleSubmit} noValidate className="space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <Alert tone="success">{success}</Alert>
        <Alert>{error}</Alert>
        <FormField label="Bank name" maxLength={100} autoComplete="organization" {...field('bank_name')} />
        <FormField
          label="SWIFT code"
          maxLength={11}
          hint="8 or 11 letters or digits, e.g. DBNKGMGM."
          className="[&_input]:uppercase"
          autoCapitalize="characters"
          {...field('swift_code')}
        />
        <FormField label="Established on" type="date" max={todayIso()} {...field('established_date')} />
        <Button type="submit" loading={submitting} loadingText="Saving…">
          {exists ? 'Save changes' : 'Save bank details'}
        </Button>
      </form>
    </section>
  )
}
