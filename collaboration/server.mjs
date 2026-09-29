import { mkdirSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { Server } from '@hocuspocus/server'
import { SQLite } from '@hocuspocus/extension-sqlite'
import { TiptapTransformer } from '@hocuspocus/transformer'
import TiptapDocument from '@tiptap/extension-document'
import Paragraph from '@tiptap/extension-paragraph'
import Text from '@tiptap/extension-text'
import { diffChars } from 'diff'
import * as Y from 'yjs'

const secret = process.env.COLLABORATION_SECRET || ''
const internalUrl = (process.env.COLLABORATION_INTERNAL_URL || 'http://127.0.0.1').replace(/\/$/, '')
const host = process.env.COLLABORATION_HOST || '127.0.0.1'
const port = Number(process.env.COLLABORATION_PORT || 1234)
const databasePath = resolve(process.env.COLLABORATION_DB_PATH || 'storage/collaboration.sqlite')
const extensions = [TiptapDocument, Paragraph, Text]
const states = new Map()
const reconciliationOrigin = Symbol('collaboration-reconciliation')
const colors = ['#176b87', '#8b4f9c', '#b05b28', '#287a4b', '#a43d55', '#5368a8', '#7a6428']

if (secret.length < 32) throw new Error('COLLABORATION_SECRET precisa ter ao menos 32 caracteres.')
if (!Number.isInteger(port) || port < 1 || port > 65535) throw new Error('COLLABORATION_PORT inválida.')
mkdirSync(dirname(databasePath), { recursive: true })

class CollaborationApiError extends Error {
  constructor(status, code, message) {
    super(message)
    this.status = status
    this.code = code
  }
}

const normalizeText = value => String(value ?? '').replace(/\r\n?/g, '\n')

const textToJson = text => ({
  type: 'doc',
  content: normalizeText(text).split('\n').map(line => ({
    type: 'paragraph',
    ...(line === '' ? {} : { content: [{ type: 'text', text: line }] }),
  })),
})

const nodeText = node => {
  if (!node) return ''
  if (node.type === 'text') return node.text || ''
  if (node.type === 'hardBreak') return '\n'
  return Array.isArray(node.content) ? node.content.map(nodeText).join('') : ''
}

const documentText = document => {
  const json = TiptapTransformer.fromYdoc(document, 'default')
  return normalizeText((json.content || []).map(nodeText).join('\n'))
}

const replaceDocumentText = (document, text) => {
  const source = TiptapTransformer.toYdoc(textToJson(text), 'default', extensions)
  const sourceFragment = source.getXmlFragment('default')
  const targetFragment = document.getXmlFragment('default')
  document.transact(() => {
    if (targetFragment.length) targetFragment.delete(0, targetFragment.length)
    const nodes = sourceFragment.toArray().map(node => node.clone())
    if (nodes.length) targetFragment.insert(0, nodes)
  }, reconciliationOrigin)
}

const cloneDocument = document => {
  const clone = new Y.Doc()
  Y.applyUpdate(clone, Y.encodeStateAsUpdate(document))
  return clone
}

const operationsBetween = (before, after) => {
  const operations = []
  let position = 0
  let removed = 0
  let inserted = ''
  const flush = () => {
    if (!removed && inserted === '') return
    operations.push({ start: position, delete: removed, insert: inserted })
    position += Array.from(inserted).length
    removed = 0
    inserted = ''
  }
  for (const chunk of diffChars(before, after)) {
    if (!chunk.added && !chunk.removed) {
      flush()
      position += Array.from(chunk.value).length
      continue
    }
    if (chunk.removed) removed += Array.from(chunk.value).length
    if (chunk.added) inserted += chunk.value
  }
  flush()
  return operations
}

const api = async (endpoint, payload) => {
  let response
  try {
    response = await fetch(`${internalUrl}${endpoint}`, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-collaboration-secret': secret,
      },
      body: JSON.stringify(payload),
    })
  } catch (error) {
    throw new CollaborationApiError(503, 'COLLABORATION_API_UNAVAILABLE', `Aplicação PHP indisponível: ${error.message}`)
  }
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new CollaborationApiError(response.status, data.error || 'COLLABORATION_API_ERROR', data.message || `Aplicação PHP respondeu ${response.status}.`)
  return data
}

const snapshot = (token, document) => api('/internal/collaboration/snapshot', { token, document })

const reconcileState = async (state, liveDocument, token, documentName) => {
  const fresh = await snapshot(token, documentName)
  replaceDocumentText(state.shadow, fresh.content)
  state.content = normalizeText(fresh.content)
  state.version = Number(fresh.version)
  state.reconcile = true
  if (state.pending === 0 && documentText(liveDocument) !== state.content) {
    replaceDocumentText(liveDocument, state.content)
    state.reconcile = false
  }
}

const persistenceMessage = (connection, payload) => {
  try { connection?.sendStateless(JSON.stringify(payload)) } catch {}
}
const logPersistence = (phase, details) => {
  process.stdout.write(`${JSON.stringify({ event: 'collaboration-persistence', phase, ...details })}\n`)
}

const collaboration = new Server({
  name: 'conselho-colaborativo',
  address: host,
  port,
  stopOnSignals: false,
  debounce: 1000,
  maxDebounce: 5000,
  quiet: true,
  extensions: [new SQLite({ database: databasePath })],

  async onAuthenticate({ token, documentName, requestHeaders }) {
    if (!token) throw new Error('Credencial de colaboração ausente.')
    logPersistence('snapshot-start', { documentName })
    let fresh
    try {
      fresh = await snapshot(token, documentName)
      logPersistence('snapshot-end', { documentName, userId: fresh.user.id, receivedVersion: fresh.version, status: 200 })
    } catch (error) {
      logPersistence('snapshot-error', { documentName, status: error.status || 500, code: error.code || 'COLLABORATION_API_UNAVAILABLE' })
      throw error
    }
    const forwarded = requestHeaders.get('x-forwarded-for') || ''
    return {
      token,
      documentName,
      snapshot: fresh,
      user: fresh.user,
      ip: forwarded.split(',')[0].trim() || requestHeaders.get('x-real-ip') || 'collaboration-service',
      userAgent: requestHeaders.get('user-agent') || 'collaboration-service',
      color: colors[Number(fresh.user.id) % colors.length],
    }
  },

  async onLoadDocument({ context, document, documentName }) {
    const fresh = context.snapshot || await snapshot(context.token, documentName)
    if (documentText(document) !== normalizeText(fresh.content)) replaceDocumentText(document, fresh.content)
    states.set(documentName, {
      content: normalizeText(fresh.content),
      version: Number(fresh.version),
      shadow: cloneDocument(document),
      queue: Promise.resolve(),
      pending: 0,
      reconcile: false,
    })
  },

  async beforeHandleAwareness({ context, states: awarenessStates }) {
    if (!context?.user) return
    for (const [clientId, awareness] of awarenessStates) {
      if (!awareness) continue
      awarenessStates.set(clientId, {
        ...awareness,
        user: { id: context.user.id, name: context.user.name, color: context.color },
      })
    }
  },

  async onChange({ context, document, documentName, update, connection, transactionOrigin }) {
    if (transactionOrigin === reconciliationOrigin || !context?.token) return
    const state = states.get(documentName)
    if (!state) return
    const capturedUpdate = Uint8Array.from(update)
    state.pending++
    state.queue = state.queue.then(async () => {
      const candidate = cloneDocument(state.shadow)
      Y.applyUpdate(candidate, capturedUpdate)
      const nextContent = documentText(candidate)
      const operations = operationsBetween(state.content, nextContent)
      if (operations.length === 0) {
        Y.applyUpdate(state.shadow, capturedUpdate)
        logPersistence('save-noop', { documentName, userId: context.user.id, version: state.version, status: 200 })
        persistenceMessage(connection, { type: 'saved', version: state.version, saved_at: new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }) })
        return
      }
      try {
        logPersistence('save-start', { documentName, userId: context.user.id, expectedVersion: state.version, operations: operations.length })
        const saved = await api('/internal/collaboration/save', {
          token: context.token,
          document: documentName,
          content: nextContent,
          version: state.version,
          operations,
          ip: context.ip,
          user_agent: context.userAgent,
        })
        Y.applyUpdate(state.shadow, capturedUpdate)
        state.content = nextContent
        state.version = Number(saved.version)
        logPersistence('save-end', { documentName, userId: context.user.id, expectedVersion: state.version - 1, receivedVersion: state.version, status: 200 })
        persistenceMessage(connection, { type: 'saved', version: state.version, saved_at: saved.saved_at, updated_by: saved.updated_by })
      } catch (error) {
        logPersistence('save-error', { documentName, userId: context.user.id, expectedVersion: state.version, status: error.status || 500, code: error.code || 'COLLABORATION_SAVE_FAILED' })
        state.reconcile = true
        if (error instanceof CollaborationApiError && error.status === 409) {
          await reconcileState(state, document, context.token, documentName).catch(() => {})
        }
        persistenceMessage(connection, {
          type: 'rejected',
          code: error.code || 'COLLABORATION_SAVE_FAILED',
          message: error.message || 'A alteração não pôde ser salva.',
        })
      }
    }).catch(error => {
      state.reconcile = true
      persistenceMessage(connection, { type: 'rejected', code: 'COLLABORATION_SAVE_FAILED', message: error.message || 'A alteração não pôde ser salva.' })
    }).finally(() => {
      state.pending--
      if (state.pending === 0 && state.reconcile) {
        if (documentText(document) !== state.content) replaceDocumentText(document, state.content)
        state.reconcile = false
      }
    })
    await state.queue
  },

  async onStateless({ connection, documentName, payload }) {
    let request
    try { request = JSON.parse(payload) } catch { return }
    if (request.type !== 'confirm-save') return
    if (!Number.isSafeInteger(request.id) || !Number.isSafeInteger(request.revision) || typeof request.content !== 'string') return
    const state = states.get(documentName)
    if (!state) return
    const expectedContent = normalizeText(request.content)
    if (expectedContent !== state.content && Array.isArray(request.update) && request.update.length > 0 && request.update.length <= 1000000 && request.update.every(byte => Number.isInteger(byte) && byte >= 0 && byte <= 255) && connection?.context?.token) {
      state.queue = state.queue.then(async () => {
        if (expectedContent === state.content) return
        const context = connection.context
        try {
          const candidate = cloneDocument(state.shadow)
          Y.applyUpdate(candidate, Uint8Array.from(request.update))
          const candidateContent = documentText(candidate)
          if (candidateContent !== expectedContent && documentText(connection.document) !== expectedContent) return
          const nextContent = expectedContent
          const operations = operationsBetween(state.content, nextContent)
          if (!operations.length) return
          logPersistence('save-start', { documentName, userId: context.user?.id, expectedVersion: state.version, operations: operations.length })
          const saved = await api('/internal/collaboration/save', {
            token: context.token,
            document: documentName,
            content: nextContent,
            version: state.version,
            operations,
            ip: context.ip,
            user_agent: context.userAgent,
          })
          state.shadow = candidateContent === expectedContent ? candidate : cloneDocument(connection.document)
          state.content = nextContent
          const previousVersion = state.version
          state.version = Number(saved.version)
          logPersistence('save-end', { documentName, userId: context.user?.id, expectedVersion: previousVersion, receivedVersion: state.version, status: 200 })
          persistenceMessage(connection, { type: 'saved', version: state.version, saved_at: saved.saved_at, updated_by: saved.updated_by })
        } catch (error) {
          logPersistence('save-error', { documentName, userId: context.user?.id, expectedVersion: state.version, status: error.status || 500, code: error.code || 'COLLABORATION_SAVE_FAILED' })
          persistenceMessage(connection, { type: 'rejected', code: error.code || 'COLLABORATION_SAVE_FAILED', message: error.message || 'A alteração não pôde ser salva.' })
        }
      })
    }
    await state.queue
    if (expectedContent === state.content) {
      persistenceMessage(connection, { type: 'confirmed', id: request.id, revision: request.revision, version: state.version })
    } else {
      persistenceMessage(connection, { type: 'confirmation-pending', id: request.id, revision: request.revision })
    }
  },

  async afterUnloadDocument({ documentName }) {
    states.delete(documentName)
  },
})

collaboration.listen().then(() => {
  process.stdout.write(`Conselho colaborativo ouvindo em ${host}:${port}\n`)
})

const shutdown = async signal => {
  process.stdout.write(`Encerrando serviço colaborativo (${signal})…\n`)
  await collaboration.destroy()
  process.exit(0)
}

process.on('SIGTERM', () => shutdown('SIGTERM'))
process.on('SIGINT', () => shutdown('SIGINT'))
