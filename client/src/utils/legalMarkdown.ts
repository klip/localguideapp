/**
 * The small Markdown subset legal documents are stored in
 * (`legal_documents.body`, see `api/sql/024_legal_documents.sql`): `## ` and
 * `### ` headings, `- ` list items, and paragraphs separated by blank lines.
 * Parsed into blocks that `LegalDocument.vue` renders as plain text — never
 * as HTML, so nothing in the stored text can inject markup.
 */
export type LegalBlock =
  | { kind: 'heading'; text: string }
  | { kind: 'subheading'; text: string }
  | { kind: 'paragraph'; text: string }
  | { kind: 'list'; items: string[] }

export function parseLegalMarkdown(body: string): LegalBlock[] {
  const blocks: LegalBlock[] = []
  let paragraph: string[] = []
  let list: string[] | null = null

  const flush = () => {
    if (paragraph.length) blocks.push({ kind: 'paragraph', text: paragraph.join(' ') })
    if (list) blocks.push({ kind: 'list', items: list })
    paragraph = []
    list = null
  }

  for (const raw of body.replace(/\r\n/g, '\n').split('\n')) {
    const line = raw.trim()
    if (line === '') {
      flush()
    } else if (line.startsWith('### ')) {
      flush()
      blocks.push({ kind: 'subheading', text: line.slice(4) })
    } else if (line.startsWith('## ')) {
      flush()
      blocks.push({ kind: 'heading', text: line.slice(3) })
    } else if (line.startsWith('- ')) {
      if (paragraph.length) flush()
      list ??= []
      list.push(line.slice(2))
    } else if (list) {
      // A continuation line of the last list item.
      list[list.length - 1] += ` ${line}`
    } else {
      paragraph.push(line)
    }
  }
  flush()
  return blocks
}

/**
 * Splits text around `[...]` — a legal-entity placeholder the operator hasn't
 * filled in yet (`legal_placeholders`) — so it can be highlighted.
 */
export function placeholderSegments(text: string): { text: string; placeholder: boolean }[] {
  return text
    .split(/(\[[^\]]+\])/)
    .filter((part) => part !== '')
    .map((part) => ({ text: part, placeholder: /^\[[^\]]+\]$/.test(part) }))
}

/** A heading's number — "9. Privacy notice" → "9", "9.8 Storage" → "9.8" — or undefined. */
export function sectionNumber(heading: string): string | undefined {
  return /^(\d+(?:\.\d+)*)\.?\s/.exec(heading)?.[1]
}
