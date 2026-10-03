import { useState } from 'react'
import { getErrorMessage } from '../lib/api.js'
import Alert from './Alert.jsx'
import Button from './Button.jsx'
import Modal from './Modal.jsx'

/**
 * Asks before a destructive action. onConfirm returns a promise; if it fails
 * (e.g. a 409 "can't be deleted") the backend's message is shown in the dialog.
 */
export default function ConfirmDialog({ title, children, confirmLabel, busyLabel, tone = 'danger', onConfirm, onClose }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function handleConfirm() {
    setBusy(true)
    setError('')
    try {
      await onConfirm()
    } catch (err) {
      setError(getErrorMessage(err))
      setBusy(false)
    }
  }

  return (
    <Modal title={title} onClose={onClose}>
      <div className="space-y-4 text-sm text-slate-700">
        {children}
        <Alert>{error}</Alert>
      </div>
      <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button variant="secondary" onClick={onClose}>
          {error ? 'Close' : 'Cancel'}
        </Button>
        {!error && (
          <Button variant={tone} onClick={handleConfirm} loading={busy} loadingText={busyLabel}>
            {confirmLabel}
          </Button>
        )}
      </div>
    </Modal>
  )
}
