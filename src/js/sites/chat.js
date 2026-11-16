import Api from "../lib/Api"
import { message as notice } from "../lib/toast"

/**
 * Widżet czatu z asystentem AI (markup: templates/_chat-widget.html.twig, backend: src/inc/assistant/).
 * Jedna ciągła rozmowa na użytkownika; ta sama skrzynka przyjmuje powiadomienia systemowe (role `notification`).
 * Odpowiedzi asystenta przychodzą jako bezpieczny HTML wyrenderowany po stronie serwera (pole `html`).
 */
document.addEventListener("DOMContentLoaded", async () => {
  const root = /** @type {HTMLElement|null} */ (document.querySelector(".mpr-chat"))
  if (!root) return

  const panel = /** @type {HTMLElement} */ (root.querySelector(".mpr-chat__panel"))
  const toggle = /** @type {HTMLButtonElement} */ (root.querySelector(".js-chat-toggle"))
  const badge = /** @type {HTMLElement} */ (root.querySelector(".js-chat-badge"))
  const list = /** @type {HTMLElement} */ (root.querySelector(".js-chat-messages"))
  const form = /** @type {HTMLFormElement} */ (root.querySelector(".js-chat-form"))
  const input = /** @type {HTMLTextAreaElement} */ (root.querySelector(".js-chat-input"))
  const sendButton = /** @type {HTMLButtonElement} */ (root.querySelector(".js-chat-send"))
  const attachButton = /** @type {HTMLButtonElement} */ (root.querySelector(".js-chat-attach"))
  const fileInput = /** @type {HTMLInputElement} */ (root.querySelector(".js-chat-file"))
  const fileList = /** @type {HTMLElement} */ (root.querySelector(".js-chat-files"))
  // Muszą się zgadzać z CHAT_MAX_* w config.php (serwer i tak waliduje).
  const MAX_FILES = 3
  const MAX_FILE_BYTES = 1_500_000
  const MAX_IMAGE_SIDE = 1600
  const GREETING = "Cześć! Jestem asystentem Uprzejmie Donoszę. Zapytaj mnie o działanie serwisu, przepisy albo swoje zgłoszenia."

  let loaded = false
  let busy = false
  let canAttach = false
  /** @type {{name:string, data:string}[]} */
  let pending = []

  const setBadge = (/** @type {number} */ n) => {
    badge.hidden = n < 1
    badge.textContent = n > 9 ? "9+" : String(n)
  }

  // Bez Api.js celowo: niezarejestrowany użytkownik dostaje tu 403 i nie ma zobaczyć toasta z błędem — po prostu bez widżetu.
  try {
    const response = await fetch("/api/chat/unread", { headers: { Accept: "application/json" } })
    if (!response.ok) return
    const status = await response.json()
    setBadge(status.unread)
    canAttach = !!status.canAttach
    root.hidden = false
  } catch {
    return
  }

  const scrollDown = () => { list.scrollTop = list.scrollHeight }

  /** @param {{role:string, content:string, html?:string, meta?:Object}} m @returns {HTMLElement} */
  const bubble = (m) => {
    const el = document.createElement("div")
    el.className = `mpr-chat__msg mpr-chat__msg--${m.role}`
    if (m.role === "user") {
      el.textContent = m.content
      const names = (m.meta?.attachments || []).map(/** @param {{name:string}} a */ a => `📎 ${a.name}`)
      if (names.length) {
        const files = document.createElement("div")
        files.className = "mpr-chat__attached"
        files.textContent = names.join("  ")
        el.append(files)
      }
      return el
    }
    if (m.role === "notification") {
      const label = document.createElement("strong")
      label.textContent = "Powiadomienie"
      el.append(label, document.createElement("br"))
    }
    const body = document.createElement("div")
    if (m.html) body.innerHTML = m.html
    else body.textContent = m.content
    body.querySelectorAll("a").forEach(a => {
      if (/^https?:/i.test(a.getAttribute("href") || "")) { a.target = "_blank"; a.rel = "noopener noreferrer" }
    })
    el.append(body)
    const link = m.meta?.link
    if (link && (link.startsWith("/") || link.startsWith("https://"))) {
      const a = document.createElement("a")
      a.href = link
      a.className = "mpr-chat__link"
      a.textContent = "Zobacz"
      el.append(a)
    }
    if (m.meta?.contactUrl) {
      const a = document.createElement("a")
      a.href = m.meta.contactUrl
      a.className = "mpr-button mpr-chat__contact"
      a.textContent = "Przejdź do kontaktu"
      el.append(a)
    }
    return el
  }

  const add = (/** @type {any} */ m) => { list.append(bubble(m)); scrollDown() }

  const load = async () => {
    list.replaceChildren()
    const { messages } = await new Api("/api/chat").getJson()
    if (!messages.length) {
      add({ role: "assistant", content: GREETING })
    }
    messages.forEach(add)
    setBadge(0) // GET oznacza powiadomienia jako przeczytane
    loaded = true
  }

  const open = async (/** @type {boolean} */ show) => {
    panel.hidden = !show
    toggle.setAttribute("aria-expanded", String(show))
    if (!show) return
    if (!loaded) await load().catch(() => {})
    else setBadge(0)
    input.focus()
  }

  const setBusy = (/** @type {boolean} */ value) => {
    busy = value
    sendButton.disabled = value
    list.querySelector(".mpr-chat__typing")?.remove()
    if (value) {
      const t = document.createElement("div")
      t.className = "mpr-chat__msg mpr-chat__msg--assistant mpr-chat__typing"
      t.textContent = "Piszę odpowiedź…"
      list.append(t)
      scrollDown()
    }
  }

  const renderPending = () => {
    fileList.replaceChildren(...pending.map((f, i) => {
      const li = document.createElement("li")
      li.textContent = `📎 ${f.name} `
      const remove = document.createElement("button")
      remove.type = "button"
      remove.textContent = "✕"
      remove.setAttribute("aria-label", `Usuń ${f.name}`)
      remove.addEventListener("click", () => { pending.splice(i, 1); renderPending() })
      li.append(remove)
      return li
    }))
    fileList.hidden = !pending.length
  }

  const readAsDataUrl = (/** @type {Blob} */ blob) => new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(/** @type {string} */ (reader.result))
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(blob)
  })

  /** Zdjęcie → JPEG do MAX_IMAGE_SIDE px (jak w appce; przy okazji znika EXIF z lokalizacją). */
  const shrinkImage = async (/** @type {File} */ file) => {
    const bitmap = await createImageBitmap(file)
    const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(bitmap.width, bitmap.height))
    const canvas = document.createElement("canvas")
    canvas.width = Math.round(bitmap.width * scale)
    canvas.height = Math.round(bitmap.height * scale)
    const ctx = /** @type {CanvasRenderingContext2D} */ (canvas.getContext("2d"))
    ctx.fillStyle = "#fff" // PNG z przezroczystością
    ctx.fillRect(0, 0, canvas.width, canvas.height)
    ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
    const blob = await new Promise(resolve => canvas.toBlob(resolve, "image/jpeg", 0.85))
    return /** @type {Blob} */ (blob)
  }

  attachButton.addEventListener("click", () => {
    if (!canAttach) {
      notice("Załączniki (zdjęcia i PDF) są dostępne dla patronów: patronite.pl/uprzejmiedonosze")
      return
    }
    fileInput.click()
  })

  fileInput.addEventListener("change", async () => {
    for (const file of Array.from(fileInput.files || [])) {
      if (pending.length >= MAX_FILES) { notice(`Maksymalnie ${MAX_FILES} załączniki.`); break }
      try {
        const blob = file.type === "application/pdf" ? file : await shrinkImage(file)
        if (blob.size > MAX_FILE_BYTES) { notice(`Plik „${file.name}” jest za duży (max 1,5 MB).`); continue }
        pending.push({ name: file.name, data: /** @type {string} */ (await readAsDataUrl(blob)) })
      } catch {
        notice(`Nie można otworzyć pliku „${file.name}”.`)
      }
    }
    fileInput.value = ""
    renderPending()
  })

  const submit = async () => {
    const text = input.value.trim()
    if ((!text && !pending.length) || busy) return
    const attachments = pending
    input.value = ""
    pending = []
    renderPending()
    add({ role: "user", content: text, meta: { attachments: attachments.map(a => ({ name: a.name })) } })
    setBusy(true)
    try {
      const { message } = await new Api("/api/chat").post({ message: text, attachments })
      setBusy(false)
      add(message)
    } catch {
      setBusy(false) // komunikat błędu pokazał już toast z Api.js
    }
  }

  toggle.addEventListener("click", () => open(panel.hidden))
  root.querySelector(".js-chat-close")?.addEventListener("click", () => open(false))
  root.querySelector(".js-chat-reset")?.addEventListener("click", async () => {
    if (!confirm("Usunąć zapisaną rozmowę z asystentem?")) return
    await new Api("/api/chat").delete({})
    await load()
  })
  form.addEventListener("submit", e => { e.preventDefault(); submit() })
  input.addEventListener("keydown", e => {
    if (e.key === "Enter" && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit() }
  })
  root.addEventListener("keydown", e => { if (e.key === "Escape" && !panel.hidden) open(false) })
})
