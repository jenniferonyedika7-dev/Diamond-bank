import { useEffect, useState } from 'react'
import Alert from '../../components/Alert.jsx'
import Loading from '../../components/Loading.jsx'
import PageHeader from '../../components/PageHeader.jsx'
import { KycBadge } from '../../components/StatusBadges.jsx'
import { api, getErrorMessage } from '../../lib/api.js'
import { formatDate } from '../../lib/format.js'

const GENDER = { M: 'Male', F: 'Female' }

/** Read-only: customers can't change their own KYC details; branch staff do. */
export default function ProfilePage() {
  const [profile, setProfile] = useState(null)
  const [error, setError] = useState('')

  useEffect(() => {
    let ignore = false
    api
      .get('/api/v1/customer/profile')
      .then(({ data }) => !ignore && setProfile(data.data))
      .catch((err) => !ignore && setError(getErrorMessage(err)))
    return () => {
      ignore = true
    }
  }, [])

  if (error) return <Alert>{error}</Alert>
  if (!profile) return <Loading />

  const rows = [
    ['Full name', `${profile.first_name} ${profile.last_name}`],
    ['Date of birth', formatDate(profile.date_of_birth)],
    ['Gender', GENDER[profile.gender]],
    ['National ID', profile.national_id],
    ['Phone', profile.phone],
    ['Email', profile.email],
    ['Home branch', profile.branch_name],
    ['Customer since', formatDate(profile.registration_date)],
  ]

  return (
    <section className="max-w-2xl space-y-6">
      <PageHeader title="Profile" />
      <Alert tone="info">To change your details, visit your branch.</Alert>

      <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
        <p className="mb-4 text-sm text-slate-700">
          Identity verification: <KycBadge status={profile.kyc_status} />
        </p>
        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt className="text-slate-500">{label}</dt>
              <dd className="mt-0.5 break-words text-slate-900">{value || '—'}</dd>
            </div>
          ))}
        </dl>
      </div>

      {profile.addresses.length > 0 && (
        <div className="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200">
          <h2 className="mb-3 text-lg font-semibold text-navy-900">Addresses</h2>
          <ul className="space-y-1 text-sm text-slate-700">
            {profile.addresses.map((a) => (
              <li key={`${a.address_type}-${a.city_street}`}>
                <span className="font-medium">{a.address_type}:</span> {a.city_street}, {a.country_region}
                {a.postal_code && ` ${a.postal_code}`}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
