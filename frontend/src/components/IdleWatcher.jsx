import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation } from 'react-router'
import { api } from '../lib/api.js'
import { lastPing, markPing, share, subscribe } from '../lib/idle.js'
import Button from './Button.jsx'
import Modal from './Modal.jsx'

const WARN_BEFORE_MS = 60_000
const PING_EVERY_MS = 30_000
const ACTIVITY_EVENTS = ['pointerdown', 'keydown', 'wheel', 'touchstart']

/**
 * Idle logout on the client side; the server is the authority (EnsureSessionNotIdle).
 * Real activity in this tab (clicks, typing, scrolling, navigation) is reported with
 * POST auth/activity, at most every 30 s across all tabs. The deadline comes from
 * the server's expires_in, so the clocks can't drift. 60 s before it, a warning
 * offers "Stay signed in"; at the deadline GET auth/session either reports a newer
 * deadline (another tab was active) or the server's idle 401, which the api
 * interceptor turns into onIdleLogout. Background requests never count.
 */
export default function IdleWatcher() {
  const location = useLocation()
  const [deadline, setDeadline] = useState(null) // ms since epoch
  const [now, setNow] = useState(() => Date.now())
  const firstLocation = useRef(true)

  const applyExpiresIn = useCallback((expiresIn) => {
    const next = Date.now() + expiresIn * 1000
    setDeadline(next)
    return next
  }, [])

  const ping = useCallback(
    async (force = false) => {
      if (!force && Date.now() - lastPing() < PING_EVERY_MS) return
      markPing()
      try {
        const { data } = await api.post('/api/v1/auth/activity')
        share({ type: 'deadline', deadline: applyExpiresIn(data.data.expires_in) })
      } catch {
        // 401 idle: handled by the api interceptor.
      }
    },
    [applyExpiresIn],
  )

  // Where the server's clock stands now; opening or reloading a page is not activity.
  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/auth/session')
      .then(({ data }) => !ignore && applyExpiresIn(data.data.expires_in))
      .catch(() => {})
    return () => {
      ignore = true
    }
  }, [applyExpiresIn])

  // Real user activity in this tab.
  useEffect(() => {
    const onActivity = () => ping()
    ACTIVITY_EVENTS.forEach((type) => window.addEventListener(type, onActivity, { capture: true, passive: true }))
    return () => ACTIVITY_EVENTS.forEach((type) => window.removeEventListener(type, onActivity, { capture: true }))
  }, [ping])

  // Navigation is activity too (not the page the tab opened on).
  useEffect(() => {
    if (firstLocation.current) {
      firstLocation.current = false
      return
    }
    ping()
  }, [location.pathname, ping])

  // A newer deadline from another tab.
  useEffect(() => subscribe((message) => message?.type === 'deadline' && setDeadline(message.deadline)), [])

  // Tick once a second near the end (countdown), otherwise wake up for the warning.
  useEffect(() => {
    if (deadline === null) return undefined
    const untilWarning = deadline - WARN_BEFORE_MS - Date.now()
    const timer = setTimeout(() => setNow(Date.now()), untilWarning > 0 ? untilWarning : 1000)
    return () => clearTimeout(timer)
  }, [deadline, now])

  // The deadline passed: ask the server (it logs out, or reports a newer deadline).
  useEffect(() => {
    if (deadline === null || now < deadline) return undefined
    let ignore = false
    api
      .get('/api/v1/auth/session')
      .then(({ data }) => !ignore && applyExpiresIn(Math.max(data.data.expires_in, 1)))
      .catch(() => {})
    return () => {
      ignore = true
    }
  }, [deadline, now, applyExpiresIn])

  if (deadline === null || deadline - now > WARN_BEFORE_MS) return null
  const seconds = Math.max(Math.ceil((deadline - now) / 1000), 0)

  return (
    <Modal title="Are you still there?" onClose={() => ping(true)}>
      <p className="text-sm text-slate-700" aria-live="polite">
        You will be logged out in {seconds} {seconds === 1 ? 'second' : 'seconds'} because there has been no activity.
      </p>
      <div className="mt-6 flex justify-end">
        <Button onClick={() => ping(true)}>Stay signed in</Button>
      </div>
    </Modal>
  )
}
