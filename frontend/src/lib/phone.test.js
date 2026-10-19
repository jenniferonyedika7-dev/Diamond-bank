import assert from 'node:assert/strict'
import { test } from 'node:test'
import { isGambianMobile, phoneInputProps, stripSpaces } from './phone.js'

test('accepts a mobile number for each prefix, with or without spaces', () => {
  for (const phone of ['831234567', '861234567', '871234567', '87 123 4567']) {
    assert.equal(isGambianMobile(phone), true, phone)
  }
})

test('refuses wrong lengths, other prefixes, old numbers and letters', () => {
  for (const phone of ['87123456', '8712345678', '841234567', '7012345', '87abc4567', '', '+220871234567']) {
    assert.equal(isGambianMobile(phone), false, phone)
  }
})

test('removes spaces as the user types and pastes', () => {
  assert.equal(stripSpaces('87 123 4567'), '871234567')

  let value
  const props = phoneInputProps((v) => (value = v))
  props.onChange({ target: { value: '87 12' } })
  assert.equal(value, '8712')

  const input = { value: '', selectionStart: 0, selectionEnd: 0 }
  props.onPaste({ preventDefault() {}, currentTarget: input, clipboardData: { getData: () => '87 123 4567' } })
  assert.equal(value, '871234567')
})
