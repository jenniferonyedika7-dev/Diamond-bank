// Mirrors Password::defaults() in backend/app/Providers/AppServiceProvider.php.
const PASSWORD_RULES = ['At least 8 characters', 'At least one letter', 'At least one number']

export default function PasswordRules({ id, extra = [] }) {
  return (
    <div id={id} className="rounded-lg bg-navy-50 px-4 py-3 text-sm text-navy-900">
      <p className="font-medium">Your password must have:</p>
      <ul className="mt-1 list-disc space-y-0.5 pl-5">
        {[...PASSWORD_RULES, ...extra].map((rule) => (
          <li key={rule}>{rule}</li>
        ))}
      </ul>
    </div>
  )
}
