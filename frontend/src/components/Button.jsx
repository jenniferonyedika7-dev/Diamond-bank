import Spinner from './Spinner.jsx'

const VARIANTS = {
  primary:
    'bg-navy-900 text-white hover:bg-navy-800 focus-visible:outline-accent-500 disabled:bg-navy-900/60',
  danger: 'bg-red-700 text-white hover:bg-red-800 focus-visible:outline-red-700 disabled:bg-red-700/60',
  secondary:
    'bg-white text-navy-900 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 focus-visible:outline-navy-600 disabled:text-slate-400',
}

/**
 * While loading the button is disabled (no double submits) and shows
 * loadingText, e.g. "Signing in…".
 */
export default function Button({
  children,
  loading = false,
  loadingText,
  disabled,
  variant = 'primary',
  className = '',
  type = 'button',
  ...props
}) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold shadow-sm transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-not-allowed ${VARIANTS[variant]} ${className}`}
      {...props}
    >
      {loading && <Spinner />}
      {loading ? (loadingText ?? children) : children}
    </button>
  )
}
