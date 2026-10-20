import { buttonClass } from './buttonStyles.js'
import Spinner from './Spinner.jsx'

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
      className={buttonClass(variant, className)}
      {...props}
    >
      {loading && <Spinner />}
      {loading ? (loadingText ?? children) : children}
    </button>
  )
}
