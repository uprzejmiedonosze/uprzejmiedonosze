document.addEventListener('DOMContentLoaded', () => {
  const input = document.getElementById('delete-account-email')
  const submit = document.getElementById('delete-account-submit')
  if (!input || !submit) return

  const expected = (input.dataset.expected || '').trim().toLowerCase()

  input.addEventListener('input', () => {
    submit.disabled = input.value.trim().toLowerCase() !== expected
  })
})
