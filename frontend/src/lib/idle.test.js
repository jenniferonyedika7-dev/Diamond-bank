import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { onLoggedOut, shareLoggedOut } from './idle.js'

// idle.js holds this tab's channel; a second channel with the same name plays another open tab.
let otherTab

beforeEach(() => {
  otherTab = new BroadcastChannel('diamond-idle')
})

afterEach(() => {
  otherTab.close()
})

/** The next message the other tab receives. */
function nextMessage() {
  return new Promise((resolve) => otherTab.addEventListener('message', (event) => resolve(event.data), { once: true }))
}

/** Resolves with what onLoggedOut passed on, or 'nothing' if it stayed silent. */
function loggedOutWithin(ms, subscribeAndPost) {
  return new Promise((resolve) => {
    const timer = setTimeout(() => {
      unsubscribe()
      resolve('nothing')
    }, ms)
    const unsubscribe = onLoggedOut((message) => {
      clearTimeout(timer)
      unsubscribe()
      resolve({ message })
    })
    subscribeAndPost(unsubscribe)
  })
}

test('Log out tells the other tabs, without a message', async () => {
  const received = nextMessage()
  shareLoggedOut()
  assert.deepEqual(await received, { type: 'logged-out', message: undefined })
})

test('idle logout tells the other tabs, with its message', async () => {
  const received = nextMessage()
  shareLoggedOut('You were logged out after 5 minutes of inactivity.')
  assert.deepEqual(await received, {
    type: 'logged-out',
    message: 'You were logged out after 5 minutes of inactivity.',
  })
})

test('a tab hears a logout from another tab', async () => {
  const result = await loggedOutWithin(1000, () => otherTab.postMessage({ type: 'logged-out' }))
  assert.deepEqual(result, { message: undefined })
})

test('a tab ignores a new deadline from another tab', async () => {
  const result = await loggedOutWithin(200, () => otherTab.postMessage({ type: 'deadline', deadline: Date.now() }))
  assert.equal(result, 'nothing')
})

test('a tab stops listening once unsubscribed', async () => {
  const result = await loggedOutWithin(200, (unsubscribe) => {
    unsubscribe()
    otherTab.postMessage({ type: 'logged-out' })
  })
  assert.equal(result, 'nothing')
})
