// Проверка токена, выданного PHP (selftest печатает его): node tools/check-token.mjs ТОКЕН
// Берёт WF_RT_ALG и WF_RT_VERIFY_KEYS из окружения (как сервер). Выход 0 — токен прошёл проверку.
import { parseKeyring, verifyToken } from '../lib/tokens.js';

const token = process.argv[2];
const alg = process.env.WF_RT_ALG || 'ed25519';
const keys = parseKeyring(process.env.WF_RT_VERIFY_KEYS);
if (!token || keys.size === 0) {
  console.error('Использование: WF_RT_ALG=ed25519 WF_RT_VERIFY_KEYS=p1:КЛЮЧ node tools/check-token.mjs ТОКЕН');
  process.exit(2);
}
const r = verifyToken(token, { alg, keys });
console.log(r.ok ? 'OK ' + JSON.stringify(r.payload) : 'ОШИБКА ' + r.error);
process.exit(r.ok ? 0 : 1);
