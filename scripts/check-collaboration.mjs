import { accessSync, constants, existsSync } from 'node:fs'
import { dirname, resolve } from 'node:path'

const errors = []
const major = Number(process.versions.node.split('.')[0])
const secret = process.env.COLLABORATION_SECRET || ''
const websocketUrl = process.env.COLLABORATION_WS_URL || ''
const internalUrl = process.env.COLLABORATION_INTERNAL_URL || ''
const databasePath = resolve(process.env.COLLABORATION_DB_PATH || 'storage/collaboration.sqlite')
const bundlePath = resolve('apps/preconselho-web/public/assets/collaboration.js')

if (major < 22) errors.push(`Node.js 22+ é obrigatório; encontrado ${process.version}.`)
if (secret.length < 32) errors.push('COLLABORATION_SECRET precisa ter ao menos 32 caracteres.')
if (!/^wss?:\/\//.test(websocketUrl)) errors.push('COLLABORATION_WS_URL precisa começar com ws:// ou wss://.')
if (!/^https?:\/\//.test(internalUrl)) errors.push('COLLABORATION_INTERNAL_URL precisa começar com http:// ou https://.')
if (!existsSync(bundlePath)) errors.push('O bundle collaboration.js não existe. Execute npm run build.')
try { accessSync(dirname(databasePath), constants.R_OK | constants.W_OK) } catch { errors.push(`O diretório de ${databasePath} precisa existir e permitir leitura/escrita.`) }

if (errors.length) {
  process.stderr.write(`${errors.join('\n')}\n`)
  process.exit(1)
}

process.stdout.write(`Colaboração atendida (Node ${process.version}, WebSocket ${websocketUrl}).\n`)
