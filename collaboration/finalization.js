export const createFinalization = ({ save, connection, confirmSave, waitFor, showStatus, notify, submit }) => {
  let busy = false
  let submitting = false
  return async (button, form) => {
    if (busy || submitting) return
    busy = true
    if (button) button.disabled = true
    const fail = message => {
      busy = false
      if (button) button.disabled = false
      showStatus(message)
      notify(message)
    }
    const current = () => connection()
    const connectionError = 'A conexão com o editor foi interrompida. Aguarde a reconexão antes de finalizar.'
    const syncError = 'Aguarde a sincronização do editor antes de finalizar a turma.'
    if (!current().connected) return fail(connectionError)
    if (!current().synced) return fail(syncError)
    if (save.rejected) return fail(save.rejected)
    if (save.pending) {
      showStatus('Salvando as últimas alterações antes de finalizar…')
      confirmSave()
      const ready = await waitFor(() => {
        const { connected, synced } = current()
        return save.canFinalize(connected, synced) || !!save.rejected || !connected || !synced
      })
      if (!ready) return fail('Ainda não foi possível confirmar o salvamento. Aguarde alguns segundos e tente novamente.')
    }
    const { connected, synced } = current()
    if (!connected) return fail(connectionError)
    if (!synced) return fail(syncError)
    if (save.rejected) return fail(save.rejected)
    if (!save.canFinalize(connected, synced)) return fail('Ainda não foi possível confirmar o salvamento. Aguarde alguns segundos e tente novamente.')
    submitting = true
    submit(form)
  }
}
