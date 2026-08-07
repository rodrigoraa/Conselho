import assert from 'node:assert/strict'
import { createServer } from 'node:http'
import { spawn } from 'node:child_process'
import { existsSync, unlinkSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { HocuspocusProvider } from '@hocuspocus/provider'
import { TiptapTransformer } from '@hocuspocus/transformer'
import Document from '@tiptap/extension-document'
import Paragraph from '@tiptap/extension-paragraph'
import Text from '@tiptap/extension-text'
import * as Y from 'yjs'

const secret = '0123456789abcdef0123456789abcdef0123456789abcdef'
const apiPort = 18181 + Math.floor(Math.random() * 500)
const collaborationPort = 12600 + Math.floor(Math.random() * 500)
const database = join(tmpdir(), `conselho-collaboration-${process.pid}.sqlite`)
const documentName = 'council:1:1'
const state = { content: '', owners: [], version: 1 }
const users = {
  professor1: { id: 1, name: 'Professor Um', role: 'PROFESSOR' },
  professor2: { id: 2, name: 'Professor Dois', role: 'PROFESSOR' },
}

const readBody = request => new Promise((resolve, reject) => {
  let body = ''
  request.setEncoding('utf8')
  request.on('data', chunk => { body += chunk })
  request.on('end', () => { try { resolve(JSON.parse(body || '{}')) } catch (error) { reject(error) } })
  request.on('error', reject)
})

const send = (response, status, payload) => {
  response.writeHead(status, { 'content-type': 'application/json' })
  response.end(JSON.stringify(payload))
}

const api = createServer(async (request, response) => {
  try {
    if (request.headers['x-collaboration-secret'] !== secret) return send(response, 403, { success: false, error: 'FORBIDDEN', message: 'Segredo inválido.' })
    const body = await readBody(request)
    const user = users[body.token]
    if (!user || body.document !== documentName) return send(response, 403, { success: false, error: 'FORBIDDEN', message: 'Token inválido.' })
    if (request.url === '/internal/collaboration/snapshot') return send(response, 200, { success: true, user, period: 1, class: 1, content: state.content, version: state.version })
    if (request.url !== '/internal/collaboration/save') return send(response, 404, { success: false })
    if (Number(body.version) !== state.version) return send(response, 409, { success: false, error: 'VERSION_CONFLICT', message: 'Versão divergente.' })
    const characters = Array.from(state.content)
    const owners = [...state.owners]
    for (const operation of body.operations || []) {
      const start = Number(operation.start)
      const remove = Number(operation.delete)
      for (let index = start; index < start + remove; index++) {
        if (owners[index] !== user.id) return send(response, 422, { success: false, error: 'FOREIGN_TEXT_PROTECTED', message: 'Você só pode alterar ou apagar os trechos que escreveu.' })
      }
      const inserted = Array.from(String(operation.insert || ''))
      characters.splice(start, remove, ...inserted)
      owners.splice(start, remove, ...inserted.map(() => user.id))
    }
    if (characters.join('') !== body.content) return send(response, 422, { success: false, error: 'EDIT_MISMATCH', message: 'Conteúdo divergente.' })
    state.content = body.content
    state.owners = owners
    state.version++
    return send(response, 200, { success: true, version: state.version, saved_at: '12:00', updated_by: user.name })
  } catch (error) {
    return send(response, 500, { success: false, error: 'TEST_ERROR', message: error.message })
  }
})

const listen = server => new Promise((resolve, reject) => {
  server.once('error', reject)
  server.listen(apiPort, '127.0.0.1', resolve)
})

const close = server => new Promise(resolve => server.close(resolve))
const waitForExit = (child, timeout = 3000) => {
  if (child.exitCode !== null) return Promise.resolve(true)
  return Promise.race([
    new Promise(resolve => child.once('exit', () => resolve(true))),
    new Promise(resolve => setTimeout(() => resolve(false), timeout)),
  ])
}
const wait = (condition, timeout = 8000) => new Promise((resolve, reject) => {
  const started = Date.now()
  const verify = () => {
    if (condition()) return resolve()
    if (Date.now() - started > timeout) return reject(new Error('Tempo esgotado aguardando sincronização.'))
    setTimeout(verify, 30)
  }
  verify()
})

const textToJson = text => ({ type: 'doc', content: String(text).split('\n').map(line => ({ type: 'paragraph', ...(line ? { content: [{ type: 'text', text: line }] } : {}) })) })
const nodeText = node => node?.type === 'text' ? node.text || '' : (node?.content || []).map(nodeText).join('')
const documentText = document => (TiptapTransformer.fromYdoc(document, 'default').content || []).map(nodeText).join('\n')

const replaceText = (document, text) => {
  const source = TiptapTransformer.toYdoc(textToJson(text), 'default', [Document, Paragraph, Text])
  const target = document.getXmlFragment('default')
  const incoming = source.getXmlFragment('default')
  document.transact(() => {
    if (target.length) target.delete(0, target.length)
    target.insert(0, incoming.toArray().map(node => node.clone()))
  })
}

const appendText = (document, text) => {
  const fragment = document.getXmlFragment('default')
  const paragraph = fragment.get(fragment.length - 1)
  let ytext = paragraph?.get(paragraph.length - 1)
  if (!(ytext instanceof Y.XmlText)) {
    ytext = new Y.XmlText()
    paragraph.insert(paragraph.length, [ytext])
  }
  ytext.insert(ytext.length, text)
}

const createClient = token => {
  const document = new Y.Doc()
  const messages = []
  const provider = new HocuspocusProvider({
    url: `ws://127.0.0.1:${collaborationPort}`,
    name: documentName,
    document,
    token,
    onStateless: ({ payload }) => { try { messages.push(JSON.parse(payload)) } catch {} },
  })
  return {
    document,
    provider,
    messages,
    async synced() { await wait(() => provider.synced) },
    async message(type, after = 0) { await wait(() => messages.slice(after).some(message => message.type === type)); return messages.slice(after).find(message => message.type === type) },
    destroy() { provider.destroy(); document.destroy() },
  }
}

let collaboration
let first
let second

try {
  await listen(api)
  collaboration = spawn(process.execPath, ['collaboration/server.mjs'], {
    cwd: process.cwd(),
    env: {
      ...process.env,
      COLLABORATION_SECRET: secret,
      COLLABORATION_INTERNAL_URL: `http://127.0.0.1:${apiPort}`,
      COLLABORATION_HOST: '127.0.0.1',
      COLLABORATION_PORT: String(collaborationPort),
      COLLABORATION_DB_PATH: database,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  let output = ''
  collaboration.stdout.on('data', chunk => { output += chunk })
  collaboration.stderr.on('data', chunk => { output += chunk })
  await wait(() => output.includes('Conselho colaborativo ouvindo'))

  first = createClient('professor1')
  await first.synced()
  const firstMessageIndex = first.messages.length
  replaceText(first.document, 'Gabriel apresentou evolução.')
  await first.message('saved', firstMessageIndex)
  assert.equal(state.content, 'Gabriel apresentou evolução.')

  second = createClient('professor2')
  await second.synced()
  assert.equal(documentText(second.document), state.content)
  const secondMessageIndex = second.messages.length
  appendText(second.document, ' Bruno precisa de apoio.')
  await second.message('saved', secondMessageIndex)
  await wait(() => documentText(first.document) === state.content)
  assert.equal(state.content, 'Gabriel apresentou evolução. Bruno precisa de apoio.')

  const simultaneousFirst = first.messages.length
  const simultaneousSecond = second.messages.length
  appendText(first.document, ' Observação um.')
  appendText(second.document, ' Observação dois.')
  await first.message('saved', simultaneousFirst)
  await second.message('saved', simultaneousSecond)
  await wait(() => documentText(first.document) === state.content && documentText(second.document) === state.content)
  assert.match(state.content, /Observação um\./)
  assert.match(state.content, /Observação dois\./)

  const protectedContent = state.content
  const rejectionIndex = second.messages.length
  const paragraph = second.document.getXmlFragment('default').get(0)
  paragraph.get(0).delete(0, 1)
  const rejected = await second.message('rejected', rejectionIndex)
  assert.equal(rejected.code, 'FOREIGN_TEXT_PROTECTED')
  await wait(() => documentText(first.document) === protectedContent && documentText(second.document) === protectedContent)
  assert.equal(state.content, protectedContent)

  process.stdout.write('Colaboração integrada: dois usuários sincronizados, alterações simultâneas convergentes e texto alheio protegido.\n')
} finally {
  first?.destroy()
  second?.destroy()
  if (collaboration && collaboration.exitCode === null) {
    collaboration.kill('SIGTERM')
    await waitForExit(collaboration)
    if (collaboration.exitCode === null) {
      collaboration.kill('SIGKILL')
      await waitForExit(collaboration)
    }
  }
  api.closeAllConnections?.()
  await close(api).catch(() => {})
  for (const suffix of ['', '-shm', '-wal']) {
    const file = database + suffix
    if (existsSync(file)) {
      try { unlinkSync(file) } catch {}
    }
  }
}
