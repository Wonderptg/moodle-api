# Student UI Prototype

这个目录是一个不依赖构建工具的静态前端样板。

它现在不再代表“整个学生产品”，而是代表三层产品结构里的第三层：

1. `课表页 Timetable`
2. `今日页 Today`
3. `上课页 Session`

这个原型目前主要覆盖的是第 3 层，也就是 `Session`。

完整产品定义见：

- `/Users/wonder/Documents/moodle/docs/TIMETABLE_DAILY_SESSION_ARCHITECTURE.md`

## 目标

- 验证 `Session` 页的主气质
- 验证连续课堂对话流是否成立
- 验证课堂块插在聊天流中是否自然
- 先把执行页做清楚，再往上接 `Today` 和 `Timetable`

## 文件

- `index.html`
  - 当前 `Session` 页样板
- `styles.css`
  - 视觉样式
- `lesson-data.js`
  - mock session 数据
- `app.js`
  - 把 lesson blocks 渲染成页面

## 这版原型在完整架构中的位置

- `Timetable`
  - 稳定课表骨架
- `Today`
  - AI 生成的日计划和 patch 结果
- `Session`
  - 当前这个原型，负责一节课的执行体验

## 这版原型表达的技术架构

- `Student UI`
  - session stream、课堂块、学生输入区
- `Lesson Adapter`
  - 把 OpenClaw 输出翻译成课堂块
- `OpenClaw Core`
  - session、skills、streaming、tool routing
- `Moodle Adapter`
  - `moodle_cli.py` / `local_aiagentapi`

## 下一步接真实能力时建议

1. 把这个原型明确收敛为 `Session` 页
2. 另补一个 `Today` 页面原型，承接日计划和 patch
3. 再补一个 `Timetable` 页面原型，承接固定课表
4. 最后再把三页统一接到 OpenClaw 和 Moodle 真数据
