const TONES = {
  error: 'border-red-200 bg-red-50 text-red-800',
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  info: 'border-navy-100 bg-navy-50 text-navy-900',
}

export default function Alert({ tone = 'error', children, className = '' }) {
  if (!children) return null
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={`rounded-lg border px-4 py-3 text-sm ${TONES[tone]} ${className}`}
    >
      {children}
    </div>
  )
}
