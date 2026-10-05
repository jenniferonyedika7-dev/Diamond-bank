import { useState } from 'react'
import Alert from '../components/Alert.jsx'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import Modal from '../components/Modal.jsx'
import { api, getErrorMessage, getFieldErrors } from '../lib/api.js'

/**
 * Renames a customer or staff login (PUT {endpoint} { user_name }). The user
 * stays signed in; they sign in with the new name from now on. Admins can't
 * set passwords: users change their own.
 */
export default function UsernameDialog({ endpoint, name, currentUserName, onClose, onDone }) {
  const [userName, setUserName] = useState(currentUserName)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setError('')
    setFieldErrors({})
    try {
      const { data } = await api.put(endpoint, { user_name: userName.trim() })
      onDone(data.message)
    } catch (err) {
      const fields = getFieldErrors(err)
      setFieldErrors(fields)
      if (!fields.user_name) setError(getErrorMessage(err))
      setSubmitting(false)
    }
  }

  const unchanged = userName.trim() === currentUserName || userName.trim() === ''

  return (
    <Modal title={`Change username: ${name}`} onClose={onClose}>
      <form onSubmit={handleSubmit} noValidate className="space-y-4">
        <p className="text-sm text-slate-600">
          Currently <strong>@{currentUserName}</strong>. They stay signed in and use the new username next time they sign in. Let them
          know about the change. Their password is not affected.
        </p>
        <Alert>{error}</Alert>
        <FormField
          id="user_name"
          label="New username"
          required
          autoComplete="off"
          minLength={3}
          maxLength={50}
          value={userName}
          onChange={(e) => setUserName(e.target.value)}
          hint="3 to 50 characters: letters, numbers, dots, dashes and underscores."
          error={fieldErrors.user_name}
        />
        <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" loading={submitting} loadingText="Saving…" disabled={unchanged}>
            Change username
          </Button>
        </div>
      </form>
    </Modal>
  )
}
