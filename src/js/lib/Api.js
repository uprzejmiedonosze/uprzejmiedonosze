import { error } from './toast'

export default class Api {
  constructor(url, mute404=false) {
    this.url = url
    this.mute404 = mute404
  }

  async getJson() {
    const response = await fetch(this.url, {
      headers: {
        'Accept': 'application/json'
      },
      method: 'GET'
    })
    return await this.#parseResponse(response)
  }
  
  async getHtml() {
    const response = await fetch(this.url, {
      headers: {
        'Accept': 'text/html'
      },
      method: 'GET'
    })
    const repl = await response.text()
    if (response.ok) return repl
    throw new Error(repl)
  }
  
  async post(data, headers={}) {
    return await this.#postOrPatch(data, 'POST', headers)
  }
  
  async patch(data, headers={}) {
    return await this.#postOrPatch(data, 'PATCH', headers)
  }
  
  async #postOrPatch(data, method, headers) {
    const response = await fetch(this.url, {
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ...headers
      },
      method,
      body: JSON.stringify(data)
    })
    return await this.#parseResponse(response)
  }
  
  async #parseResponse(response) {
    const repl = await response.json()
    if (response.ok) return repl
    if (response.status != 404 || !this.mute404)
      error(repl.error)
    throw new Error(repl.error, repl)
  }

}
