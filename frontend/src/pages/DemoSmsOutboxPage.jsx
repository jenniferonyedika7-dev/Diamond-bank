import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router'
import Alert from '../components/Alert.jsx'
import AuthLayout from '../components/AuthLayout.jsx'
import Button from '../components/Button.jsx'
import Loading from '../components/Loading.jsx'
import { api, getErrorMessage } from '../lib/api.js'
import { formatDateTime } from '../lib/format.js'

/** Why a temporary password is hidden (the API sends it already masked). */
function statusLabel(sms) {
  if (sms.status === 'USED') return `Used ${formatDateTime(sms.status_at)}`
  return sms.status === 'REPLACED' ? 'Replaced' : 'Expired'
}

/**
 * The simulated SMS inbox: every text the bank "sent" in the last 24 hours,
 * newest first. Development only; the backend answers 404 unless APP_ENV=local.
 * A temporary password that can no longer be used arrives masked, with a status.
 */
export default function DemoSmsOutboxPage() {
  const [state, setState] = useState({ messages: null, error: '', notFound: false })

  const load = useCallback(
    () =>
      api
        .get('/api/v1/demo/sms-outbox')
        .then(({ data }) => setState({ messages: data.data, error: '', notFound: false }))
        .catch((err) =>
          setState({ messages: [], error: err.response?.status === 404 ? '' : getErrorMessage(err), notFound: err.response?.status === 404 }),
        ),
    [],
  )

  useEffect(() => {
    load()
  }, [load])

  if (state.notFound) {
    return (
      <AuthLayout title="Not available" subtitle="The demo SMS outbox only exists in the local development environment.">
        <Link to="/login" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
          ← Back to sign in
        </Link>
      </AuthLayout>
    )
  }

  return (
    <AuthLayout title="Demo SMS outbox" subtitle="Texts sent in the last 24 hours, newest first.">
      <div className="space-y-4">
        <p role="note" className="rounded-lg bg-amber-100 px-4 py-2 text-center text-sm font-semibold text-amber-900 ring-1 ring-amber-300">
          Simulated SMS — development only.
        </p>
        <div className="flex items-center justify-between gap-2">
          <Link to="/login" className="text-sm font-medium text-navy-700 underline-offset-2 hover:underline">
            ← Sign in
          </Link>
          <Button variant="secondary" className="py-1.5" onClick={load}>
            Refresh
          </Button>
        </div>
        <Alert>{state.error}</Alert>
        {state.messages === null ? (
          <Loading />
        ) : state.messages.length === 0 ? (
          <p className="text-center text-sm text-slate-600">No messages in the last 24 hours.</p>
        ) : (
          <ul className="space-y-3 rounded-2xl bg-slate-100 p-3">
            {state.messages.map((sms) => (
              <li key={sms.demo_sms_id}>
                <p className="mb-1 flex flex-wrap justify-between gap-x-2 px-1 text-xs text-slate-500">
                  <span>
                    To {sms.recipient_name ?? 'unknown'} · <span className="font-mono">{sms.phone}</span>
                  </span>
                  <time dateTime={sms.sent_at}>{formatDateTime(sms.sent_at)}</time>
                </p>
                <p className="max-w-[90%] rounded-2xl rounded-tl-sm bg-white px-4 py-3 text-sm text-slate-900 shadow-sm ring-1 ring-slate-200 select-all">
                  {sms.message}
                </p>
                {sms.status && <p className="mt-1 px-1 text-xs text-slate-500">{statusLabel(sms)}</p>}
              </li>
            ))}
          </ul>
        )}
      </div>
    </AuthLayout>
  )
}
