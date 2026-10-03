import { createContext, useContext } from 'react'

export const StaffBranchContext = createContext(null)

/** { status: 'loading'|'ready'|'error', branch, department, error } from <StaffShell>. */
export function useStaffBranch() {
  return useContext(StaffBranchContext)
}
