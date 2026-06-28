import { toast } from 'vue-sonner'

/**
 * Open an attachment in a new browser tab for preview (images, PDFs, …).
 *
 * The API serves attachments with `Content-Disposition: attachment`, which
 * forces a download even with `target="_blank"`. To preview inline we fetch the
 * file as a blob (with session cookies) and point a new tab at its object URL,
 * which the browser renders inline for previewable types and downloads
 * otherwise.
 *
 * The blank tab is opened synchronously inside the click gesture so popup
 * blockers don't intercept it; it's then redirected once the blob resolves.
 */
export async function openAttachmentInNewTab(url: string, fileName?: string): Promise<void> {
  const win = window.open('', '_blank')

  try {
    const response = await fetch(url, { credentials: 'include' })
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`)
    }

    const blob = await response.blob()
    const objectUrl = URL.createObjectURL(blob)

    if (win) {
      win.location.href = objectUrl
    } else {
      window.open(objectUrl, '_blank', 'noopener')
    }

    // Revoke after the tab has had time to load the resource.
    window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
  } catch {
    win?.close()
    toast.error(`Could not open ${fileName ?? 'attachment'}.`)
  }
}
