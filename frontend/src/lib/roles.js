export const ROLE_HOME = {
  admin: '/admin',
  staff: '/staff',
  customer: '/customer',
}

export const ROLE_LABEL = {
  admin: 'Administrator',
  staff: 'Staff',
  customer: 'Customer',
}

/** Where a logged-in user belongs: the password change comes before everything else. */
export function dashboardPath(user) {
  if (!user) return '/login'
  if (user.must_change_password) return '/change-password'
  return ROLE_HOME[user.role] ?? '/login'
}

/** full_name is only returned for employees (admin, staff); customers fall back to user_name. */
export function displayName(user) {
  return user?.full_name || user?.user_name || ''
}
