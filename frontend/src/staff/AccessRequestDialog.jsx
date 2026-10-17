import { useState } from 'react'
import Alert from '../components/Alert.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import Modal from '../components/Modal.jsx'
import { Checkbox, RadioGroup } from '../shared/access/Choices.jsx'
import { api, getErrorMessage, getFieldErrors } from '../lib/api.js'

const CHECKS = [
  ['dob_confirmed', 'Date of birth confirmed'],
  ['phone_confirmed', 'Registered phone number confirmed'],
  ['history_reviewed', 'Account and transaction history reviewed'],
]
const DECLARATION = 'I confirm this customer contacted our branch and I verified their identity.'

/**
 * Staff record an account access request for a customer locked out of their
 * login. An administrator approves it; nothing changes until then.
 * expired: the temporary password expired, so only "unblock and reset" helps.
 */
export default function AccessRequestDialog({ customer, expired, onClose, onDone }) {
  const [form, setForm] = useState({
    contact_channel: '',
    dob_confirmed: false,
    phone_confirmed: false,
    history_reviewed: false,
    declaration_confirmed: false,
    request_type: expired ? 'UNBLOCK_AND_RESET' : '',
    notes: '',
  })
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const set = (key) => (value) => setForm((prev) => ({ ...prev, [key]: value }))
  const complete =
    form.contact_channel && form.request_type && form.notes.trim() && CHECKS.every(([key]) => form[key]) && form.declaration_confirmed

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post(`/api/v1/staff/customers/${customer.customer_id}/access-requests`, form)
      onDone(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  return (
    <Modal title={`Account access request: ${customer.first_name} ${customer.last_name}`} onClose={onClose} size="lg">
      <form onSubmit={handleSubmit} noValidate className="space-y-5">
        <p className="text-sm text-slate-600">
          An administrator reviews the request. The customer gets an SMS now, and another when it has been decided.
        </p>
        <Alert>{error}</Alert>

        <RadioGroup
          name="contact_channel"
          legend="How did the customer contact us?"
          value={form.contact_channel}
          onChange={set('contact_channel')}
          error={fieldErrors.contact_channel}
          options={[
            { value: 'BRANCH_VISIT', label: 'Branch visit' },
            { value: 'PHONE_CALL', label: 'Phone call' },
          ]}
        />

        <fieldset className="space-y-2">
          <legend className="mb-1.5 text-sm font-medium text-slate-700">Identity checks (all required)</legend>
          {CHECKS.map(([key, label]) => (
            <Checkbox key={key} id={key} label={label} checked={form[key]} onChange={set(key)} error={fieldErrors[key]} />
          ))}
        </fieldset>

        <RadioGroup
          name="request_type"
          legend="What does the customer need?"
          value={form.request_type}
          onChange={set('request_type')}
          error={fieldErrors.request_type}
          options={[
            ...(expired ? [] : [{ value: 'UNBLOCK_ONLY', label: 'Unblock only', hint: 'The customer remembers their password.' }]),
            {
              value: 'UNBLOCK_AND_RESET',
              label: 'Unblock and send a temporary password',
              hint: 'The system texts a temporary password to the registered phone. Nobody at the bank sees it.',
            },
          ]}
        />

        <FormField
          id="notes"
          as="textarea"
          label="Notes"
          required
          maxLength={1000}
          value={form.notes}
          onChange={(e) => set('notes')(e.target.value)}
          hint="What the customer told you and how you checked their identity."
          error={fieldErrors.notes}
        />

        <div className="rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
          <Checkbox
            id="declaration_confirmed"
            label={DECLARATION}
            checked={form.declaration_confirmed}
            onChange={set('declaration_confirmed')}
            error={fieldErrors.declaration_confirmed}
          />
        </div>

        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting} loadingText="Sending…" disabled={!complete}>
            Send for approval
          </Button>
        </div>
      </form>
    </Modal>
  )
}
