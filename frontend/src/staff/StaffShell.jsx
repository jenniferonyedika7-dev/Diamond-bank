import { useEffect, useState } from 'react'
import AppLayout from '../components/AppLayout.jsx'
import { api, getErrorMessage } from '../lib/api.js'
import { StaffBranchContext, useStaffBranch } from './staffBranch.js'

/** Loads the staff member's current branch once and shows it in the header. */
export default function StaffShell() {
  const [state, setState] = useState({ status: 'loading', branch: null, department: null, error: '' })

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/staff/me/branch')
      .then(({ data }) => !ignore && setState({ status: 'ready', ...data.data, error: '' }))
      .catch((err) => !ignore && setState({ status: 'error', branch: null, department: null, error: getErrorMessage(err) }))
    return () => {
      ignore = true
    }
  }, [])

  return (
    <StaffBranchContext.Provider value={state}>
      <AppLayout headerExtra={<BranchLabel />} />
    </StaffBranchContext.Provider>
  )
}

function BranchLabel() {
  const { branch, department } = useStaffBranch()
  if (!branch) return null
  return (
    <span>
      {branch.branch_name} · {branch.branch_code}
      {department && ` · ${department.department_name}`}
    </span>
  )
}
