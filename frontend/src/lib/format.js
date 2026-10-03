const percent = new Intl.NumberFormat('en-GM', { style: 'percent', minimumFractionDigits: 2, maximumFractionDigits: 2 })
const date = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
const dateTime = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })

/** An interest rate stored as a percentage number: formatPercent('3.5') -> "3.50%". */
export function formatPercent(rate) {
  return percent.format(Number(rate) / 100)
}

/** "2026-10-03" or "2026-10-03 14:05:00" -> "3 Oct 2026" (date part only, no timezone shift). */
export function formatDate(value) {
  if (!value) return '—'
  const [y, m, d] = String(value).slice(0, 10).split('-').map(Number)
  return date.format(new Date(y, m - 1, d))
}

/** "2026-10-03 14:05:00" (server time, as stored) -> "3 Oct 2026, 14:05". */
export function formatDateTime(value) {
  if (!value) return '—'
  const [d, t = '00:00:00'] = String(value).replace('T', ' ').slice(0, 19).split(' ')
  const [y, m, day] = d.split('-').map(Number)
  const [h, min] = t.split(':').map(Number)
  return dateTime.format(new Date(y, m - 1, day, h, min))
}

/** Today as YYYY-MM-DD in the browser's timezone, for date input max attributes. */
export function todayIso() {
  const now = new Date()
  return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}
