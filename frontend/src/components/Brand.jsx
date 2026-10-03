export default function Brand({ className = '' }) {
  return (
    <span className={`inline-flex items-center gap-2 font-semibold tracking-tight ${className}`}>
      <svg viewBox="0 0 24 24" className="size-6 text-accent-400" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round" aria-hidden="true">
        <path d="M6 3h12l4 6-10 12L2 9l4-6Z" />
        <path d="M2 9h20M9 3 7.5 9 12 21l4.5-12L15 3" />
      </svg>
      Diamond Bank
    </span>
  )
}
