/**
 * fieldErrors without the message for `name`, so a corrected value never sits under an old error.
 * Returns the same object when there is nothing to clear, so React skips the re-render.
 */
export function clearFieldError(errors, name) {
  if (!(name in errors)) return errors
  const { [name]: _, ...rest } = errors
  return rest
}
