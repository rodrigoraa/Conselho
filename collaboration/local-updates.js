import * as Y from 'yjs'

export const createLocalUpdates = save => {
  let updates = []
  return {
    track(update, origin, provider) {
      if (!save.trackUpdate(origin, provider)) return false
      updates.push(Uint8Array.from(update))
      return true
    },
    merged() { return updates.length ? Array.from(Y.mergeUpdates(updates)) : [] },
    confirmed() { updates = [] },
  }
}
