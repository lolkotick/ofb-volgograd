/* Сверяет разбор постов ВК в Node-скрипте и в PHP-порте.
   Запуск из корня проекта:  node scripts/parity/compare.mjs
   Нужен установленный php в PATH. */
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const node = JSON.parse(execFileSync('node', [resolve(here, 'run.mjs')], { cwd: here, encoding: 'utf8' }));
const php  = JSON.parse(execFileSync('php',  [resolve(here, 'run.php')], { cwd: here, encoding: 'utf8' }));

let bad = 0;
node.forEach((x, i) => {
  for (const k of Object.keys(x)) {
    if (JSON.stringify(x[k]) !== JSON.stringify(php[i]?.[k])) {
      bad++;
      console.log(`--- расхождение #${i}, поле ${k}`);
      console.log('  node:', JSON.stringify(x[k])?.slice(0, 200));
      console.log('  php :', JSON.stringify(php[i]?.[k])?.slice(0, 200));
    }
  }
});
console.log(`Проверено случаев: ${node.length}. Расхождений: ${bad}.`);
process.exit(bad ? 1 : 0);
