/**
 * Shared idle-logout state for every open tab. All tabs use one session cookie,
 * so the server keeps one idle clock (EnsureSessionNotIdle). Tabs tell each other
 * about a new deadline (after any tab reported activity) and about an idle logout,
 * over a BroadcastChannel, or localStorage 'storage' events where that is missing.
 * Only timestamps are shared. If storage is blocked, each tab works alone and the
 * server still logs the session out on time.
 */
const CHANNEL = 'diamond-idle'
const MESSAGE_KEY = 'diamond-idle:message'
const PING_KEY = 'diamond-idle:last-ping'

const channel = typeof BroadcastChannel === 'undefined' ? null : new BroadcastChannel(CHANNEL)

/** Tells the other tabs: { type: 'deadline', deadline } or { type: 'logged-out', message }. */
export function share(message) {
  try {
    if (channel) channel.postMessage(message)
    else localStorage.setItem(MESSAGE_KEY, JSON.stringify({ ...message, sentAt: Date.now() }))
  } catch {
    // Storage blocked: this tab works on its own.
  }
}

/** Calls listener(message) for messages from other tabs. Returns an unsubscribe function. */
export function subscribe(listener) {
  if (channel) {
    const onMessage = (event) => listener(event.data)
    channel.addEventListener('message', onMessage)
    return () => channel.removeEventListener('message', onMessage)
  }
  const onStorage = (event) => {
    if (event.key !== MESSAGE_KEY || !event.newValue) return
    try {
      listener(JSON.parse(event.newValue))
    } catch {
      // Not ours.
    }
  }
  window.addEventListener('storage', onStorage)
  return () => window.removeEventListener('storage', onStorage)
}

/** Tells the other tabs this session has ended (idle logout with its message, or Log out without one). */
export function shareLoggedOut(message) {
  share({ type: 'logged-out', message })
}

/** Calls listener(message) when another tab says the session has ended. Returns an unsubscribe function. */
export function onLoggedOut(listener) {
  return subscribe((message) => {
    if (message?.type === 'logged-out') listener(message.message)
  })
}

/** When any tab last reported activity to the server (ms), so three tabs don't all ping. */
export function lastPing() {
  try {
    return Number(localStorage.getItem(PING_KEY)) || 0
  } catch {
    return 0
  }
}

export function markPing(at = Date.now()) {
  try {
    localStorage.setItem(PING_KEY, String(at))
  } catch {
    // Storage blocked: this tab throttles on its own.
  }
}
