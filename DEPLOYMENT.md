## GitHub → server: автодеплой

Репозиторий: `git@github.com:zapleoceo/veranda_my.git`

### 1) Что хранится в Git, а что нет

- `.env` и любые `.env.*` не коммитятся.
- Логи (`*.log`) не коммитятся.

Файлы исключений: [.gitignore](file:///d:/Projects/Veranda%20site%202/.gitignore)

### 2) Подготовка SSH-ключа для деплоя (GitHub Actions → сервер)

На своём компьютере сгенерируйте отдельный ключ для GitHub Actions:

```bash
ssh-keygen -t ed25519 -C "github-actions-veranda-deploy" -f ~/.ssh/veranda_github_actions -N ""
```

Добавьте **публичный** ключ на сервер в `~/.ssh/authorized_keys` пользователя, который владеет веб-директориями (обычно `veranda_my_usr`):

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
cat ~/.ssh/veranda_github_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

### 3) GitHub Secrets (Settings → Secrets and variables → Actions)

Нужные секреты для workflow:

- `SSH_HOST`: IP/домен сервера (например `159.253.23.113` или `veranda.my`)
- `SSH_PORT`: `22`
- `SSH_USER`: `veranda_my_usr`
- `DEPLOY_PATH`: `/var/www/veranda_my_usr/data/www/veranda.my`
- `SSH_PRIVATE_KEY`: содержимое приватного ключа `~/.ssh/veranda_github_actions` (целиком, включая строки BEGIN/END)

### 4) Workflow

Workflow деплоя находится тут: [.github/workflows/deploy.yml](file:///d:/Projects/Veranda%20site%202/.github/workflows/deploy.yml)

Триггер: push в ветку `main`.

### 5) Проверка

- Любой push в `main` запускает Action “Deploy to veranda.my”.
- После деплоя workflow выполняет `php -l` на ключевых файлах на сервере.

