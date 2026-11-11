// Генератор ключей для связки PHP ↔ realtime (РТ-02).
// Запуск: node tools/gen-keys.mjs [--hmac] [--kid-php p1] [--kid-rt r1]
// Печатает два набора значений: секреты GitHub (для PHP) и переменные сервера realtime (файл .env на VPS).
// Секретные ключи нигде не хранятся этим скриптом. Не публикуй вывод и не коммить его.
import crypto from 'node:crypto';
import { edKeyPair } from '../lib/tokens.js';
import { b64uEnc } from '../lib/proto.js';

const args = process.argv.slice(2);
const flag = (name) => args.includes(name);
const opt = (name, def) => {
  const i = args.indexOf(name);
  return i >= 0 && args[i + 1] ? args[i + 1] : def;
};
const alg = flag('--hmac') ? 'hmac' : 'ed25519';
const kidPhp = opt('--kid-php', 'p1'); // ключ, которым подписывает PHP (проверяет РТ)
const kidRt = opt('--kid-rt', 'r1');   // ключ, которым подписывает РТ (проверяет PHP)

let phpSign;
let phpPublic;
let rtSign;
let rtPublic;
if (alg === 'ed25519') {
  const a = edKeyPair();
  const b = edKeyPair();
  phpSign = b64uEnc(a.secret);
  phpPublic = b64uEnc(a.public);
  rtSign = b64uEnc(b.secret);
  rtPublic = b64uEnc(b.public);
} else {
  phpSign = phpPublic = b64uEnc(crypto.randomBytes(32)); // ключ A: токены PHP → РТ
  rtSign = rtPublic = b64uEnc(crypto.randomBytes(32));   // ключ B: квитанции РТ → PHP
}

console.log(`# Алгоритм: ${alg}. Ключи сгенерированы ${new Date().toISOString()}`);
console.log('');
console.log('# 1) GitHub → Settings → Secrets and variables → Actions → New repository secret (для PHP на InfinityFree):');
console.log(`BOBER_RT_ALG=${alg}`);
console.log(`BOBER_RT_SIGN_KID=${kidPhp}`);
console.log(`BOBER_RT_SIGN_KEY=${phpSign}`);
console.log(`BOBER_RT_VERIFY_KEYS=${kidRt}:${rtPublic}`);
console.log('BOBER_RT_URL=wss://ТВОЙ-ДОМЕН/   # адрес realtime-сервера (его создаёт РТ-01)');
console.log('');
console.log('# 2) realtime/.env на VPS (для сервера realtime):');
console.log(`WF_RT_ALG=${alg}`);
console.log(`WF_RT_SIGN_KID=${kidRt}`);
console.log(`WF_RT_SIGN_KEY=${rtSign}`);
console.log(`WF_RT_VERIFY_KEYS=${kidPhp}:${phpPublic}`);
console.log('');
console.log('# Ротация: сгенерируй новый набор с другими kid (--kid-php p2 --kid-rt r2), добавь новые ключи в списки');
console.log('# VERIFY_KEYS через запятую (kid1:ключ1,kid2:ключ2), потом переключи SIGN_KID/SIGN_KEY, через 15 минут убери старые.');
