import { useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router'

function navClass({ isActive }) {
  return `block rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-600 ${
    isActive ? 'bg-navy-900 text-white' : 'text-slate-700 hover:bg-navy-50 hover:text-navy-900'
  }`
}

/**
 * Sidebar on large screens; a "Menu" disclosure that closes on navigation below that.
 * items: [{ to, label, end? }]; label names the nav landmark ("Admin", "Staff").
 */
export default function SideNavLayout({ items, label }) {
  const location = useLocation()
  const [menu, setMenu] = useState({ open: false, path: location.pathname })
  // Close the mobile menu after navigating, without an effect.
  const open = menu.open && menu.path === location.pathname
  const current = items.find((item) => (item.end ? location.pathname === item.to : location.pathname.startsWith(item.to)))

  return (
    <div className="lg:grid lg:grid-cols-[13rem_1fr] lg:gap-8">
      <div className="mb-6 lg:hidden">
        <button
          type="button"
          aria-expanded={open}
          aria-controls="side-nav"
          onClick={() => setMenu({ open: !open, path: location.pathname })}
          className="flex w-full items-center justify-between rounded-lg bg-white px-4 py-3 text-sm font-medium text-navy-900 shadow-sm ring-1 ring-slate-200 focus-visible:outline-2 focus-visible:outline-navy-600"
        >
          <span>
            <span className="text-slate-500">Menu · </span>
            {current?.label ?? label}
          </span>
          <svg viewBox="0 0 20 20" className={`size-5 transition-transform ${open ? 'rotate-180' : ''}`} fill="currentColor" aria-hidden="true">
            <path d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" />
          </svg>
        </button>
      </div>

      <nav
        id="side-nav"
        aria-label={label}
        className={`${open ? 'block' : 'hidden'} mb-6 rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200 lg:sticky lg:top-6 lg:mb-0 lg:block lg:self-start`}
      >
        <ul className="space-y-1">
          {items.map((item) => (
            <li key={item.to}>
              <NavLink to={item.to} end={item.end} className={navClass}>
                {item.label}
              </NavLink>
            </li>
          ))}
        </ul>
      </nav>

      <div className="min-w-0">
        <Outlet />
      </div>
    </div>
  )
}
