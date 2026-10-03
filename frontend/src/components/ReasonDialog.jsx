import { useState } from 'react'
import { getErrorMessage, getFieldErrors } from '../lib/api.js'
import Alert from './Alert.jsx'
import Button from './Button.jsx'
import FormField from './FormField.jsx'
import Modal from './Modal.jsx'

/**
 * Asks for a required reason (max 255, recorded in the audit log) before an
 * action such as block, reject or freeze. onSubmit(reason) returns a promise;
 * a failure shows the backend's message and any 422 error under the field.
 */
export default function ReasonDialog({ title, intro, submitLabel, busyLabel, onSubmit, onClose }) {
  const [reason, setReason] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      await onSubmit(reason)
    } catch (err) {
      setError(getErrorMessage(err))
      setFieldErrors(getFieldErrors(err))
      setSubmitting(false)
    }
  }

  return (
    <Modal title={title} onClose={onClose}>
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        {intro && <p className="text-sm text-slate-600">{intro}</p>}
        <Alert>{error}</Alert>
        <FormField
          id="reason"
          as="textarea"
          label="Reason"
          required
          maxLength={255}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          hint={`${reason.length}/255 characters. Recorded in the audit log.`}
          error={fieldErrors.reason}
        />
        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" variant="danger" loading={submitting} loadingText={busyLabel} disabled={reason.trim() === ''}>
            {submitLabel}
          </Button>
        </div>
      </form>
    </Modal>
  )
}
