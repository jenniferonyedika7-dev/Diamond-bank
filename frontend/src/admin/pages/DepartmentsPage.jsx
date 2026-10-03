import ResourcePage from '../ResourcePage.jsx'

const config = {
  endpoint: '/api/v1/admin/departments',
  idKey: 'department_id',
  title: 'Departments',
  description: 'Staff can be assigned to a department when they are approved.',
  singular: 'department',
  emptyText: 'No departments yet. Add your first department.',
  columns: [
    { key: 'department_name', label: 'Name' },
    { key: 'employees_count', label: 'Staff', align: 'right' },
  ],
  fields: [{ name: 'department_name', label: 'Department name', maxLength: 100 }],
}

export default function DepartmentsPage() {
  return <ResourcePage config={config} />
}
