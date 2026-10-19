import Brand from './Brand.jsx'

/** Centered card for the login, registration and password pages. */
export default function AuthLayout({ title, subtitle, children, wide = false }) {
  return (
    <div className="flex min-h-dvh flex-col">
      <header className="bg-navy-900 text-white shadow-md">
        <div className="mx-auto flex h-16 max-w-5xl items-center px-4">
          <Brand className="text-lg" />
        </div>
        <div className="h-0.5 bg-gradient-to-r from-accent-500 via-accent-400 to-accent-500" aria-hidden="true" />
      </header>
      <main className="flex flex-1 justify-center bg-gradient-to-b from-navy-50 to-slate-50 px-4 py-8 sm:py-12">
        <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-md'}`}>
          <div className="overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-slate-200">
            <div className="h-1 bg-navy-900" aria-hidden="true" />
            <div className="p-6 sm:p-8">
              <h1 className="text-2xl font-semibold tracking-tight text-navy-900">{title}</h1>
              {subtitle && <p className="mt-1 text-sm text-slate-600">{subtitle}</p>}
              <div className="mt-6">{children}</div>
            </div>
          </div>
        </div>
      </main>
    </div>
  )
}
