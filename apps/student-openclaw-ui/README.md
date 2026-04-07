# Student OpenClaw UI

一个从 OpenClaw 原生 `ui/` 分叉出来的最小学生端聊天界面。

当前特点：

- 直接连接 OpenClaw Gateway WebSocket
- 使用 `chat.history` / `chat.send` / `chat.abort`
- 默认 session key 为 `agent:student-ui:main`
- 不再经过 CodePilot 的 `/api/chat` 或 `/v1/responses` 兼容层

开发：

```bash
cd /Users/wonder/Documents/moodle/apps/student-openclaw-ui
pnpm install
pnpm dev --host 127.0.0.1 --port 4030
```

构建：

```bash
cd /Users/wonder/Documents/moodle/apps/student-openclaw-ui
pnpm build
```
