import { useAuth } from '../auth/useAuth.js'
import { displayName, ROLE_LABEL } from '../lib/roles.js'

/** Placeholder dashboard shared by /admin, /staff and /customer for now. */
export default function DashboardPage() {
  const { user } = useAuth()

  return (
    <section aria-labelledby="dashboard-title">
      <p className="text-sm font-medium uppercase tracking-wide text-accent-500">{ROLE_LABEL[user.role]} dashboard</p>
      <h1 id="dashboard-title" className="mt-1 text-2xl font-semibold text-navy-900 sm:text-3xl">
        Welcome, {displayName(user)}
      </h1>

      <dl className="mt-6 grid gap-4 sm:grid-cols-2">
        <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <dt className="text-sm text-slate-500">Signed in as</dt>
          <dd className="mt-1 font-medium text-slate-900">{user.user_name}</dd>
        </div>
        <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <dt className="text-sm text-slate-500">Role</dt>
          <dd className="mt-1 font-medium text-slate-900">{ROLE_LABEL[user.role]}</dd>
        </div>
      </dl>

      <p className="mt-8 rounded-xl border border-dashed border-slate-300 bg-white/60 p-6 text-sm text-slate-600">
        More features are coming soon.
      </p>
    </section>
  )
}
