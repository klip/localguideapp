import { anchorTo, type MessagePlacement } from '@/stores/messages'

interface FieldRefs {
  email?: HTMLElement | null
  password?: HTMLElement | null
  form: HTMLElement | null
}

/**
 * Placement for a message resulting from a login/register form submit: the
 * field the message names, or the form itself when it's ambiguous or
 * general. Matched by text because the API returns a plain message string,
 * not a structured per-field error (see RequestProcessor::actionRegister()/
 * actionLogin() for the fixed set of messages this matches against).
 */
export function formMessagePlacement(text: string, refs: FieldRefs): MessagePlacement {
  const lower = text.toLowerCase()
  const mentionsEmail = lower.includes('email')
  const mentionsPassword = lower.includes('password')

  if (mentionsPassword && !mentionsEmail && refs.password) return anchorTo('input', refs.password)
  if (mentionsEmail && !mentionsPassword && refs.email) return anchorTo('input', refs.email)
  return anchorTo('element', refs.form)
}
