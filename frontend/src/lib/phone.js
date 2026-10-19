// The backend rule (App\Rules\GambianMobile), checked here too so the form can refuse
// an obvious typo before submitting. The backend stays the authority.
// 9 digits, no country code, starting 83 (QCell), 86 (Comium) or 87 (Africell) after
// PURA's September 2026 migration. Gamcel numbers are not supported.
export const GAMBIAN_MOBILE_PATTERN = /^8[367]\d{7}$/

export const PHONE_MESSAGE = 'Enter a 9-digit Gambian mobile number starting with 83, 86 or 87.'

export const PHONE_HINT = '9 digits, starting 83, 86 or 87, e.g. 871234567.'

export function stripSpaces(value) {
  return String(value ?? '').replace(/ /g, '')
}

export function isGambianMobile(value) {
  return GAMBIAN_MOBILE_PATTERN.test(stripSpaces(value))
}

/**
 * Input props for a phone field: numeric keyboard, 9 characters, with spaces removed as they
 * are typed or pasted (a paste is cleaned first, so maxLength can't cut "87 123 4567" short).
 * setValue receives the cleaned value.
 */
export function phoneInputProps(setValue) {
  return {
    type: 'tel',
    inputMode: 'numeric',
    autoComplete: 'tel',
    maxLength: 9,
    hint: PHONE_HINT,
    onChange: (event) => setValue(stripSpaces(event.target.value)),
    onPaste: (event) => {
      event.preventDefault()
      const input = event.currentTarget
      const pasted = stripSpaces(event.clipboardData.getData('text'))
      setValue((input.value.slice(0, input.selectionStart) + pasted + input.value.slice(input.selectionEnd)).slice(0, 9))
    },
  }
}
