export const createSaveState = () => {
  let revision = 0
  let confirmed = 0
  let rejected = null
  let request = 0
  return {
    get revision() { return revision },
    get pending() { return revision > confirmed },
    get rejected() { return rejected },
    edit() { revision++; rejected = null; return revision },
    request() { return { id: ++request, revision } },
    confirm(message) {
      if (message.id !== request || message.revision !== revision || rejected) return false
      confirmed = revision
      return true
    },
    reject(message) {
      if (message.id !== request || message.revision !== revision) return false
      rejected = message.message || 'Não foi possível salvar a alteração.'
      return true
    },
    rejectSave(message) { rejected = message || 'Não foi possível salvar a alteração.' },
    invalidate() { request++ },
    trackUpdate(origin, provider) {
      if (origin === provider) return false
      this.edit()
      return true
    },
    canFinalize(connected, synced) { return connected && synced && !rejected && !this.pending },
  }
}
