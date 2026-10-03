import Spinner from './Spinner.jsx'

export default function Loading({ label = 'Loading…' }) {
  return (
    <div className="flex items-center gap-2 text-sm text-slate-600" role="status">
      <Spinner /> {label}
    </div>
  )
}
