import assert from 'node:assert/strict'
import { test } from 'node:test'
import { formatMoney } from './money.js'

test('labels amounts GMD, never D', () => {
  assert.match(formatMoney(1000), /^GMD\s1,000\.00$/)
  assert.match(formatMoney('5000000.5'), /^GMD\s5,000,000\.50$/)
  assert.match(formatMoney(0, null), /^GMD\s0\.00$/)
  assert.doesNotMatch(formatMoney(1000), /^D/)
})

test("uses the account's currency code when given", () => {
  assert.match(formatMoney(1000, 'GMD'), /^GMD\s1,000\.00$/)
  assert.match(formatMoney(1000, 'USD'), /^USD\s1,000\.00$/)
})
