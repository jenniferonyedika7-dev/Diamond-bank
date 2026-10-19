// en-GM would print GMD as "D"; currencyDisplay 'code' always gives "GMD 1,500.00".
const formatters = new Map()

/**
 * Formats an amount with its currency code, e.g. formatMoney(1500) -> "GMD 1,500.00" (non-breaking
 * space, so the code never wraps away from the number). Pass the account's currency_code when the
 * API returns one; GMD otherwise.
 */
export function formatMoney(amount, currency) {
  const code = currency || 'GMD'
  if (!formatters.has(code)) {
    formatters.set(code, new Intl.NumberFormat('en-GM', { style: 'currency', currency: code, currencyDisplay: 'code' }))
  }
  return formatters.get(code).format(Number(amount))
}
