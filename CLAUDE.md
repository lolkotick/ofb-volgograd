# CLAUDE.md — сайт ВРОО «Областная федерация баскетбола»

Полная память проекта (что за сайт, что сделано, правила, что осталось): @memory.md

Кратко:
- Astro 5 (статика) + JSON в `data/` + PHP на хостинге SpaceWeb. Боевой сайт: https://basket34.ru. Репозиторий: github.com/lolkotick/ofb-volgograd (main).
- Команды: `npm ci`, `npm run dev`, `npm run build`, `node scripts/parity/compare.mjs` (нужен php).
- Пуш в `main` → GitHub Actions → GitHub Pages и FTP на хостинг. Среда Глеба: Windows + PowerShell.
- Отвечать по-русски, коротко и по делу. Не выдумывать данные федерации; ключи/пароли вводит только Глеб; проверять результат на живом сайте.
- Первое действие в новой сессии: прочитать `memory.md`, §8 «Что осталось сделать».
