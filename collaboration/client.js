import { Editor } from '@tiptap/core'
import Document from '@tiptap/extension-document'
import Paragraph from '@tiptap/extension-paragraph'
import Text from '@tiptap/extension-text'
import Collaboration from '@tiptap/extension-collaboration'
import CollaborationCaret from '@tiptap/extension-collaboration-caret'
import { HocuspocusProvider } from '@hocuspocus/provider'
import * as Y from 'yjs'

const nodeText = node => {
  if (!node) return ''
  if (node.type === 'text') return node.text || ''
  if (node.type === 'hardBreak') return '\n'
  return Array.isArray(node.content) ? node.content.map(nodeText).join('') : ''
}

const editorText = editor => (editor.getJSON().content || []).map(nodeText).join('\n').replace(/\r\n?/g, '\n')

const waitFor = (condition, timeout = 8000) => new Promise(resolve => {
  const started = Date.now()
  const verify = () => {
    if (condition()) return resolve(true)
    if (Date.now() - started >= timeout) return resolve(false)
    setTimeout(verify, 100)
  }
  verify()
})

const createCursor = user => {
  const cursor = document.createElement('span')
  cursor.className = 'collaboration-caret'
  cursor.style.borderColor = user.color
  const label = document.createElement('span')
  label.className = 'collaboration-caret-label'
  label.style.backgroundColor = user.color
  label.textContent = user.name
  cursor.append(label)
  return cursor
}

const initializeCollaboration = textarea => {
  const sourceLabel = textarea.closest('label')
  const section = textarea.closest('[data-collective-class]')
  const editorCard = textarea.closest('.shared-text-editor')
  const status = section?.querySelector('[data-shared-save-status]')
  const badge = section?.querySelector('[data-lock-badge]')
  const wrapper = document.createElement('div')
  wrapper.className = 'collaboration-editor'
  wrapper.innerHTML = '<div class="collaboration-presence"><span class="collaboration-connection" aria-live="polite">Conectando…</span><div class="collaboration-users" aria-label="Pessoas nesta turma"></div></div><div class="collaboration-surface"></div><small class="collaboration-help">As alterações aparecem ao vivo. Cada professor pode alterar somente os próprios trechos.</small>'
  sourceLabel?.classList.add('collaboration-source')
  sourceLabel?.after(wrapper)

  const connectionOutput = wrapper.querySelector('.collaboration-connection')
  const usersOutput = wrapper.querySelector('.collaboration-users')
  const surface = wrapper.querySelector('.collaboration-surface')
  const ydoc = new Y.Doc()
  let editor
  let connected = false
  let persisted = true
  let submitting = false

  const setPending = value => {
    persisted = !value
    textarea.dataset.collaborationPending = value ? '1' : '0'
  }

  const showState = (text, kind = 'neutral') => {
    connectionOutput.textContent = text
    connectionOutput.dataset.state = kind
    if (badge) {
      badge.textContent = kind === 'online' ? 'Ao vivo' : kind === 'error' ? 'Falha na conexão' : text
      badge.classList.toggle('status-aprovado', kind === 'online')
      badge.classList.toggle('status-devolvido', kind === 'error')
      badge.classList.toggle('status-enviado', kind !== 'online' && kind !== 'error')
    }
  }

  const renderUsers = states => {
    const users = new Map()
    states.forEach(state => {
      if (state.user?.id) users.set(String(state.user.id), state.user)
    })
    usersOutput.replaceChildren()
    users.forEach(user => {
      const chip = document.createElement('span')
      chip.className = 'collaboration-user'
      chip.style.setProperty('--user-color', user.color)
      chip.textContent = user.name
      usersOutput.append(chip)
    })
    usersOutput.setAttribute('aria-label', users.size ? `${users.size} pessoa(s) nesta turma` : 'Nenhuma pessoa conectada')
  }

  const provider = new HocuspocusProvider({
    url: textarea.dataset.collaborationUrl,
    name: textarea.dataset.collaborationDocument,
    document: ydoc,
    token: textarea.dataset.collaborationToken,
    flushDelay: 80,
    onStatus: ({ status: providerStatus }) => {
      connected = providerStatus === 'connected'
      textarea.dataset.collaborationConnected = connected ? '1' : '0'
      if (!connected) {
        showState(providerStatus === 'connecting' ? 'Reconectando…' : 'Desconectado', 'neutral')
        if (status) status.textContent = 'Conexão interrompida. O sistema tentará reconectar automaticamente.'
      }
    },
    onSynced: ({ state }) => {
      if (!state) return
      connected = true
      textarea.dataset.collaborationConnected = '1'
      editor?.setEditable(true)
      showState('Conectado ao vivo', 'online')
      if (status && persisted) status.textContent = '✓ Editor sincronizado. Alterações são salvas em tempo real.'
    },
    onAuthenticationFailed: ({ reason }) => {
      connected = false
      textarea.dataset.collaborationConnected = '0'
      editor?.setEditable(false)
      showState('Acesso recusado', 'error')
      if (status) status.textContent = reason || 'Seu acesso ao editor expirou. Recarregue a página.'
    },
    onAwarenessChange: ({ states }) => renderUsers(states),
    onUnsyncedChanges: ({ number }) => {
      if (number > 0) {
        setPending(true)
        if (status) status.textContent = 'Sincronizando alterações…'
      }
    },
    onStateless: ({ payload }) => {
      let message
      try { message = JSON.parse(payload) } catch { return }
      if (message.type === 'saved') {
        setPending(false)
        textarea.dataset.version = String(message.version)
        editorCard?.classList.remove('save-conflict')
        if (status) status.textContent = `✓ Alterações salvas às ${message.saved_at}.`
      }
      if (message.type === 'rejected') {
        setPending(false)
        editorCard?.classList.add('save-conflict')
        if (status) status.textContent = message.message || 'Sua alteração foi desfeita porque não era permitida.'
      }
    },
  })

  editor = new Editor({
    element: surface,
    editable: false,
    extensions: [
      Document,
      Paragraph,
      Text,
      Collaboration.configure({ document: ydoc }),
      CollaborationCaret.configure({
        provider,
        user: { name: textarea.dataset.collaborationUser, color: textarea.dataset.collaborationColor },
        render: createCursor,
        selectionRender: user => ({
          nodeName: 'span',
          class: 'collaboration-selection',
          style: `background-color: ${user.color}33`,
        }),
      }),
    ],
    editorProps: {
      attributes: {
        class: 'collaboration-prosemirror',
        role: 'textbox',
        'aria-multiline': 'true',
        'aria-label': textarea.getAttribute('aria-label') || sourceLabel?.querySelector('.sr-only')?.textContent || 'Texto coletivo da turma',
        spellcheck: 'true',
      },
    },
    onUpdate: ({ editor: currentEditor }) => {
      const content = editorText(currentEditor)
      textarea.value = content
      textarea.dispatchEvent(new CustomEvent('collaboration:update', { bubbles: true }))
      if (Array.from(content).length > Number(textarea.maxLength || 60000)) {
        if (status) status.textContent = 'O texto ultrapassou o limite permitido e a alteração será desfeita.'
      }
    },
  })

  surface.addEventListener('beforeinput', () => {
    if (!connected) return
    setPending(true)
    editorCard?.classList.remove('save-conflict')
    if (status) status.textContent = 'Sincronizando alterações…'
  })

  section?.querySelector('[data-finalize-class]')?.addEventListener('submit', async event => {
    if (submitting || (!provider.hasUnsyncedChanges && persisted && connected)) return
    event.preventDefault()
    if (!connected) {
      alert('Aguarde a reconexão do editor antes de finalizar a turma.')
      return
    }
    provider.flushPendingUpdates()
    if (status) status.textContent = 'Aguardando a confirmação do salvamento para finalizar…'
    const ready = await waitFor(() => !provider.hasUnsyncedChanges && persisted && connected)
    if (!ready) {
      alert('Ainda não foi possível confirmar o salvamento. Aguarde alguns segundos e tente novamente.')
      return
    }
    submitting = true
    HTMLFormElement.prototype.submit.call(event.currentTarget)
  })

  section?.addEventListener('toggle', async () => {
    if (section.open) {
      showState('Reconectando…', 'neutral')
      await provider.connect()
      return
    }
    provider.flushPendingUpdates()
    await waitFor(() => !provider.hasUnsyncedChanges && persisted, 5000)
    if (section.open) return
    provider.disconnect()
    connected = false
    textarea.dataset.collaborationConnected = '0'
    editor.setEditable(false)
  })

  window.addEventListener('pagehide', () => {
    provider.destroy()
    editor.destroy()
    ydoc.destroy()
  }, { once: true })
}

document.querySelectorAll('textarea[data-collaboration-token]').forEach(textarea => {
  const section = textarea.closest('[data-collective-class]')
  if (section?.open) {
    initializeCollaboration(textarea)
    return
  }
  const start = () => {
    if (!section?.open) return
    section.removeEventListener('toggle', start)
    initializeCollaboration(textarea)
  }
  section?.addEventListener('toggle', start)
})

window.addEventListener('beforeunload', event => {
  if (!document.querySelector('textarea[data-collaboration-pending="1"]')) return
  event.preventDefault()
  event.returnValue = ''
})
