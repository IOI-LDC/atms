import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import manualSource from '@/content/user-manual.md?raw'

/**
 * A Table-of-Contents node derived from a Markdown heading.
 * `##` headings become top-level sections; `###` headings become their children.
 */
export interface ManualHeading {
  id: string
  title: string
  level: number
  children: ManualHeading[]
}

interface RenderedManual {
  html: string
  toc: ManualHeading[]
}

function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^\w\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
}

/**
 * Render inline Markdown (code, images, links, bold, italic) to HTML.
 * The text is HTML-escaped first so authored content can never inject raw markup.
 */
function renderInline(text: string): string {
  return escapeHtml(text)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/!\[([^\]]*)\]\(([^)\s]+)\)/g, '<img src="$2" alt="$1" />')
    .replace(
      /\[([^\]]+)\]\(([^)\s]+)\)/g,
      '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>',
    )
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>')
    .replace(/_([^_]+)_/g, '<em>$1</em>')
}

function splitTableRow(raw: string): string[] {
  return raw
    .replace(/^\s*\|/, '')
    .replace(/\|\s*$/, '')
    .split('|')
    .map((cell) => cell.trim())
}

const BLOCK_BOUNDARY = /^(\s*$|#{1,6}\s|```|>\s?|\s*[-*+]\s+|\s*\d+\.\s+)/

/**
 * A small, dependency-free Markdown-to-HTML renderer scoped to the subset the
 * User Manual uses (headings, paragraphs, lists, tables, blockquotes, code,
 * rules, inline emphasis/links). It also extracts a nested Table of Contents
 * from `##`/`###` headings and injects matching `id`s into the output so the
 * TOC can scroll to and highlight sections.
 *
 * If full CommonMark fidelity is ever needed, swap this single function for
 * `markdown-it` — the composable's public surface stays identical.
 */
function renderManual(source: string): RenderedManual {
  const lines = source.replace(/\r\n/g, '\n').split('\n')
  const html: string[] = []
  const toc: ManualHeading[] = []
  const usedIds = new Set<string>()
  let i = 0

  const lineAt = (index: number): string => lines[index] ?? ''

  const uniqueId = (base: string): string => {
    let id = base || 'section'
    let suffix = 2
    while (usedIds.has(id)) {
      id = `${base}-${suffix++}`
    }
    usedIds.add(id)
    return id
  }

  while (i < lines.length) {
    const line = lineAt(i)

    if (/^\s*$/.test(line)) {
      i++
      continue
    }

    // Fenced code block
    if (/^```/.test(line)) {
      const buffer: string[] = []
      i++
      while (i < lines.length && !/^```/.test(lineAt(i))) {
        buffer.push(lineAt(i))
        i++
      }
      i++ // consume closing fence
      html.push(`<pre class="manual-code"><code>${escapeHtml(buffer.join('\n'))}</code></pre>`)
      continue
    }

    // ATX heading
    const heading = line.match(/^(#{1,6})\s+(.*)$/)
    if (heading) {
      const [, hashes = '', rawTitle = ''] = heading
      const level = hashes.length
      const title = rawTitle.trim()
      if (level === 2 || level === 3) {
        const id = uniqueId(slugify(title))
        html.push(
          `<h${level} id="${id}" class="manual-h${level}">${renderInline(title)}</h${level}>`,
        )
        const node: ManualHeading = { id, title, level, children: [] }
        const parent = toc[toc.length - 1]
        if (level === 3 && parent) {
          parent.children.push(node)
        } else {
          toc.push(node)
        }
      } else {
        html.push(`<h${level} class="manual-h${level}">${renderInline(title)}</h${level}>`)
      }
      i++
      continue
    }

    // Horizontal rule
    if (/^(-{3,}|\*{3,}|_{3,})\s*$/.test(line)) {
      html.push('<hr class="manual-hr" />')
      i++
      continue
    }

    // Blockquote
    if (/^>\s?/.test(line)) {
      const buffer: string[] = []
      while (i < lines.length && /^>\s?/.test(lineAt(i))) {
        buffer.push(lineAt(i).replace(/^>\s?/, ''))
        i++
      }
      html.push(`<blockquote class="manual-quote">${renderInline(buffer.join(' '))}</blockquote>`)
      continue
    }

    // Table (header row followed by a `---|---` separator)
    if (
      line.includes('|') &&
      i + 1 < lines.length &&
      /^\s*\|?[\s:|-]*-[\s:|-]*\|[\s:|-]*$/.test(lineAt(i + 1))
    ) {
      const headers = splitTableRow(line)
      i += 2 // consume header + separator
      const rows: string[][] = []
      while (i < lines.length && lineAt(i).includes('|') && !/^\s*$/.test(lineAt(i))) {
        rows.push(splitTableRow(lineAt(i)))
        i++
      }
      const head = `<tr>${headers.map((cell) => `<th>${renderInline(cell)}</th>`).join('')}</tr>`
      const body = rows
        .map((row) => `<tr>${row.map((cell) => `<td>${renderInline(cell)}</td>`).join('')}</tr>`)
        .join('')
      html.push(
        `<div class="manual-table-wrap"><table class="manual-table"><thead>${head}</thead><tbody>${body}</tbody></table></div>`,
      )
      continue
    }

    // Unordered list
    if (/^\s*[-*+]\s+/.test(line)) {
      const items: string[] = []
      while (i < lines.length && /^\s*[-*+]\s+/.test(lineAt(i))) {
        items.push(`<li>${renderInline(lineAt(i).replace(/^\s*[-*+]\s+/, ''))}</li>`)
        i++
      }
      html.push(`<ul class="manual-list">${items.join('')}</ul>`)
      continue
    }

    // Ordered list
    if (/^\s*\d+\.\s+/.test(line)) {
      const items: string[] = []
      while (i < lines.length && /^\s*\d+\.\s+/.test(lineAt(i))) {
        items.push(`<li>${renderInline(lineAt(i).replace(/^\s*\d+\.\s+/, ''))}</li>`)
        i++
      }
      html.push(`<ol class="manual-steps">${items.join('')}</ol>`)
      continue
    }

    // Paragraph (consume consecutive non-boundary lines)
    const buffer: string[] = []
    while (i < lines.length && !BLOCK_BOUNDARY.test(lineAt(i))) {
      buffer.push(lineAt(i))
      i++
    }
    html.push(`<p class="manual-p">${renderInline(buffer.join(' '))}</p>`)
  }

  return { html: html.join('\n'), toc }
}

export function useUserManual() {
  const { html, toc } = renderManual(manualSource)

  const searchQuery = ref('')
  const activeId = ref('')

  const filteredToc = computed<ManualHeading[]>(() => {
    const query = searchQuery.value.trim().toLowerCase()
    if (!query) {
      return toc
    }
    const result: ManualHeading[] = []
    for (const section of toc) {
      const sectionMatches = section.title.toLowerCase().includes(query)
      const matchingChildren = section.children.filter((child) =>
        child.title.toLowerCase().includes(query),
      )
      if (sectionMatches || matchingChildren.length > 0) {
        result.push({
          ...section,
          children: matchingChildren.length > 0 ? matchingChildren : section.children,
        })
      }
    }
    return result
  })

  function scrollToSection(id: string): void {
    activeId.value = id
    const element = document.getElementById(id)
    element?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  function printManual(): void {
    window.print()
  }

  let observer: IntersectionObserver | null = null

  onMounted(() => {
    observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            activeId.value = (entry.target as HTMLElement).id
          }
        }
      },
      { threshold: 0.1, rootMargin: '-120px 0px -65% 0px' },
    )
    requestAnimationFrame(() => {
      document
        .querySelectorAll<HTMLElement>('.manual-content [id]')
        .forEach((element) => observer?.observe(element))
    })
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
    observer = null
  })

  return { html, toc, filteredToc, searchQuery, activeId, scrollToSection, printManual }
}
