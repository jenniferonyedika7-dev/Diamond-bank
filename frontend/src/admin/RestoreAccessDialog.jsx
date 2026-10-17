import { useState } from 'react'
import Alert from '../components/Alert.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import Modal from '../components/Modal.jsx'
import { api, getErrorMessage, getFieldErrors } from '../lib/api.js'
import { RadioGroup } from '../shared/access/Choices.jsx'
import { RECOVERY_LABELS } from '../shared/access/labels.js'

/**
 * Restores a staff login locked by wrong passwords (or with an expired
 * temporary password). There is no higher approver: the form, then a confirm step.
 */
export default function RestoreAccessDialog({ user, onClose, onDone }) {
  const expired = user.recovery === 'TEMP_PASSWORD_EXPIRED'
  const [form, setForm] = useState({ contact_channel: '', verification_note: '', request_type: expired ? 'UNBLOCK_AND_RESET' : '' })
  const [confirming, setConfirming] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  const set = (key) => (value) => setForm((prev) => ({ ...prev, [key]: value }))
  const complete = form.contact_channel && form.request_type && form.verification_note.trim()
  const reset = form.request_type === 'UNBLOCK_AND_RESET'

  async function restore() {
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.post(`/api/v1/admin/staff/${user.user_id}/restore-access`, form)
      onDone(data.message)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setConfirming(false)
      setSubmitting(false)
    }
  }

  return (
    <Modal title={`Restore access: ${user.full_name}`} onClose={onClose} size="lg">
      {confirming ? (
        <div className="space-y-4 text-sm text-slate-700">
          <p>
            <strong>{user.full_name}</strong> (@{user.user_name}) will be able to sign in again.
          </p>
          <p>
            {reset
              ? `The system texts a temporary password to ${user.phone}. Nobody sees it, and they must change it when they sign in.`
              : `They keep their own password and get an SMS at ${user.phone} saying their account is unblocked.`}
          </p>
          <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={() => setConfirming(false)}>
              Back
            </Button>
            <Button onClick={restore} loading={submitting} loadingText="Restoring…">
              Restore access
            </Button>
          </div>
        </div>
      ) : (
        <form
          onSubmit={(event) => {
            event.preventDefault()
            setConfirming(true)
          }}
          noValidate
          className="space-y-5"
        >
          <p className="text-sm font-medium text-amber-800">{RECOVERY_LABELS[user.recovery]}</p>
          <Alert>{error}</Alert>
          <RadioGroup
            name="contact_channel"
            legend="How did they contact you?"
            value={form.contact_channel}
            onChange={set('contact_channel')}
            error={fieldErrors.contact_channel}
            options={[
              { value: 'IN_PERSON', label: 'In person' },
              { value: 'PHONE_CALL', label: 'Phone call' },
            ]}
          />
          <FormField
            id="verification_note"
            as="textarea"
            label="Verification note"
            required
            maxLength={1000}
            value={form.verification_note}
            onChange={(e) => set('verification_note')(e.target.value)}
            hint="How you confirmed who they are. Recorded in the audit log."
            error={fieldErrors.verification_note}
          />
          <RadioGroup
            name="request_type"
            legend="What do they need?"
            value={form.request_type}
            onChange={set('request_type')}
            error={fieldErrors.request_type}
            options={[
              ...(expired ? [] : [{ value: 'UNBLOCK_ONLY', label: 'Unblock only', hint: 'They remember their password.' }]),
              { value: 'UNBLOCK_AND_RESET', label: 'Unblock and send a temporary password', hint: `Texted to ${user.phone}.` },
            ]}
          />
          <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
            <Button variant="secondary" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" disabled={!complete}>
              Continue
            </Button>
          </div>
        </form>
      )}
    </Modal>
  )
}
