import assert from 'node:assert/strict'
import { test } from 'node:test'
import { clearFieldError } from './forms.js'

test('removes only the edited field', () => {
  assert.deepEqual(clearFieldError({ phone: 'Bad phone.', email: 'Bad email.' }, 'phone'), { email: 'Bad email.' })
})

test('handles dotted keys such as guarantor fields', () => {
  assert.deepEqual(clearFieldError({ 'guarantor.phone': 'Bad phone.', 'guarantor.name': 'Required.' }, 'guarantor.phone'), {
    'guarantor.name': 'Required.',
  })
})

test('returns the same object when the field has no error', () => {
  const errors = { email: 'Bad email.' }
  assert.equal(clearFieldError(errors, 'phone'), errors)
})

test('does not change the original errors', () => {
  const errors = { phone: 'Bad phone.' }
  clearFieldError(errors, 'phone')
  assert.deepEqual(errors, { phone: 'Bad phone.' })
})
