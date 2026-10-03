const gmd = new Intl.NumberFormat('en-GM', { style: 'currency', currency: 'GMD' })

/** Formats an amount as Gambian dalasi, e.g. formatMoney(1500) -> "GMD 1,500.00". */
export function formatMoney(amount) {
  return gmd.format(Number(amount))
}
