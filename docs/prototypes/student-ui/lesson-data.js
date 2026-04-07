const LESSON_SESSION = {
  metaChips: [
    "课程 92 · 2026四类综合",
    "今日任务 2 / 5",
    "预计 43 分钟",
  ],
  todayHero: {
    title: "早安，今天我们把“洋流与季风”学明白。",
    copy:
      "先用 8 分钟听懂核心关系，再做 3 道练习题。我会根据你的错误点自动调整后半程讲解。",
  },
  plan: [
    {
      title: "知识点引入",
      copy: "讲解季风、洋流、热量交换之间的关系，并用动态图示说明。",
      active: true,
    },
    {
      title: "即时提问",
      copy: "你可以随时打断，让我换例子、换图、再讲一遍。",
      active: false,
    },
    {
      title: "小测练习",
      copy: "做 3 道题，自动分析易错点与薄弱知识。",
      active: false,
    },
    {
      title: "针对性补讲",
      copy: "如果你错在“风向成因”，我会改讲因果链，不再重复定义。",
      active: false,
    },
    {
      title: "进入下一个任务",
      copy: "自动切到今日下一段视频或题组，不让你手动跳页面。",
      active: false,
    },
  ],
  stream: {
    title: "当前正在上课",
    subtitle: "聊天是主舞台，但不是普通聊天，而是连续的 lesson stream。",
    progress: 42,
  },
  composer: {
    status: "第一版前端建议直接接 OpenClaw 的 OpenResponses API，后面再升到 Gateway WS。",
    actions: ["再举个例子", "切到做题模式", "打开黑板"],
    placeholder:
      "我可以问：为什么海温变化会变成低压？或者说，先别讲下一题，给我一个生活化的比喻。",
    tags: ["随时打断老师", "要求换图讲解", "让 AI 直接开始下一任务"],
  },
  runtimeStatus: [
    {
      label: "当前模式",
      value: "解释 + 练习",
    },
    {
      label: "OpenClaw 接口",
      value: "OpenResponses",
    },
    {
      label: "Moodle 能力",
      value: "Quiz / Plan / Course",
    },
  ],
  autoNotes: [
    "1. 洋流先改海温，不是直接改变风。",
    "2. 海温升高，空气更容易上升。",
    "3. 上升运动会让近地面形成低压倾向。",
    "4. 低压吸引周边空气流入，风场随之变化。",
    "",
    "这里更像“AI 自动黑板”，不是一整套文档编辑器。",
  ],
  resources: [
    {
      title: "课程视频 03 · 洋流与气候",
      copy: "如果学生持续卡住，可自动切回这段视频的 02:10 - 04:00。",
    },
    {
      title: "错题组 · 季风形成题型",
      copy: "后续可自动生成一组同主题练习，强化“因果链”表述。",
    },
    {
      title: "今日计划同步",
      copy: "完成这段学习后，再把下一个任务压到今天的时间轴里。",
    },
  ],
  messages: [
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:40",
      blocks: [
        {
          type: "text",
          content:
            "早安。今天我已经把计划拆好了：先学“洋流如何影响季风”，再用 3 道题确认你能不能把因果链说清楚。你现在不需要翻课程页，我们直接开始。",
        },
      ],
    },
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:41",
      blocks: [
        {
          type: "text",
          content:
            "先抓住一句话：洋流改变海面温度，海面温度改变气压差，气压差再推动季风强弱变化。下面这张图只讲这条链路。",
        },
        {
          type: "visual",
          title: "生成式讲解图 · 洋流影响季风",
          badge: "课堂块 · visual",
          svg: `
            <svg viewBox="0 0 860 360" role="img" aria-label="洋流与季风关系图">
              <defs>
                <linearGradient id="warm" x1="0" x2="1">
                  <stop offset="0%" stop-color="#f6c56a" />
                  <stop offset="100%" stop-color="#d97706" />
                </linearGradient>
                <linearGradient id="cool" x1="0" x2="1">
                  <stop offset="0%" stop-color="#2dd4bf" />
                  <stop offset="100%" stop-color="#0f766e" />
                </linearGradient>
              </defs>
              <rect x="20" y="34" width="230" height="112" rx="24" fill="#fff8eb" stroke="#dfcfb0"/>
              <rect x="316" y="34" width="230" height="112" rx="24" fill="#f0fbf8" stroke="#b9ddd6"/>
              <rect x="610" y="34" width="230" height="112" rx="24" fill="#fff8eb" stroke="#dfcfb0"/>
              <text x="52" y="82" font-size="22" font-family="Avenir Next, sans-serif" fill="#6b4112">暖流 / 寒流变化</text>
              <text x="348" y="82" font-size="22" font-family="Avenir Next, sans-serif" fill="#0f766e">海面温度改变</text>
              <text x="643" y="82" font-size="22" font-family="Avenir Next, sans-serif" fill="#6b4112">气压差变化</text>
              <text x="66" y="114" font-size="16" font-family="Georgia, serif" fill="#6b7280">热量输送方向不同</text>
              <text x="352" y="114" font-size="16" font-family="Georgia, serif" fill="#6b7280">沿岸增温或降温</text>
              <text x="651" y="114" font-size="16" font-family="Georgia, serif" fill="#6b7280">陆海风力差异放大</text>
              <path d="M248 90 C276 90, 294 90, 316 90" stroke="url(#warm)" stroke-width="10" fill="none" stroke-linecap="round"/>
              <path d="M546 90 C574 90, 592 90, 614 90" stroke="url(#cool)" stroke-width="10" fill="none" stroke-linecap="round"/>
              <rect x="116" y="214" width="628" height="102" rx="26" fill="#ffffff" stroke="#d8d6cd"/>
              <text x="154" y="258" font-size="28" font-family="Avenir Next, sans-serif" fill="#1f2933">季风强弱 = 热量差异 + 气压差 + 风向响应</text>
              <text x="154" y="292" font-size="17" font-family="Georgia, serif" fill="#5f6c78">当沿岸海域被暖流加热，海陆热力差会被重新分配，季风就可能提前、增强，或转向更明显。</text>
            </svg>
          `,
          notes: [
            "知识点 1：洋流不是直接“吹风”，而是先改变海温。",
            "知识点 2：海温差异会重塑近海气压分布。",
            "知识点 3：季风本质上是大范围气压差驱动的风系响应。",
          ],
        },
      ],
    },
    {
      role: "user",
      speaker: "学生提问",
      time: "07:43",
      blocks: [
        {
          type: "text",
          content:
            "如果海水变暖了，为什么就一定会影响风？我感觉中间断了一步。",
        },
      ],
    },
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:43",
      blocks: [
        {
          type: "text",
          content:
            "你卡住的是“海温变暖如何变成风”。很好，这正是常见薄弱点。我换成更短的链路：海面更暖 → 上方空气更容易上升 → 近地面形成低压倾向 → 周围空气流进来，于是风向和风力都受影响。",
        },
        {
          type: "pills",
          items: [
            "AI 正在讲第二层因果，不重复定义",
            "这里可以继续问“低压为什么形成”",
            "也可以切成生活例子模式",
          ],
        },
      ],
    },
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:48",
      blocks: [
        {
          type: "text",
          content:
            "现在先做 1 道题，我想确认你是不是已经把这个因果链接上了。",
        },
        {
          type: "quiz",
          title: "即时检测 · 第 1 题",
          badge: "课堂块 · quiz",
          question:
            "某沿岸海域受暖流影响，海面温度升高。下列最可能首先发生的变化是：",
          options: [
            {
              key: "A",
              text: "近海上空空气上升增强，近地面低压倾向更明显",
              active: true,
            },
            {
              key: "B",
              text: "高空冷空气直接下沉，因此季风立刻减弱",
              active: false,
            },
            {
              key: "C",
              text: "洋流立刻改变大陆地形，导致风向改变",
              active: false,
            },
            {
              key: "D",
              text: "海水盐度先降低，再直接形成台风",
              active: false,
            },
          ],
        },
      ],
    },
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:51",
      blocks: [
        {
          type: "text",
          content:
            "你答对了，但我观察到你提问时仍然对“低压形成”不够稳。我会把今天后半段的讲解略微调整成“温度变化 → 气流升降 → 气压差”这个顺序，再带你做下一题。",
        },
        {
          type: "analysis",
          title: "AI 课堂分析",
          badge: "课堂块 · analysis",
          summary:
            "这不是成绩报告，而是下一步教学决策。系统会用你的提问、答题和停顿点判断今天该讲什么、不该讲什么。",
          metrics: [
            {
              value: "78%",
              label: "当前理解度",
            },
            {
              value: "因果链",
              label: "主要薄弱点",
            },
            {
              value: "+1 题",
              label: "建议补练数量",
            },
          ],
        },
      ],
    },
    {
      role: "ai",
      speaker: "Lobster Tutor",
      time: "07:55",
      blocks: [
        {
          type: "transition",
          title: "下一步任务切换",
          badge: "课堂块 · transition",
          summary:
            "接下来会自动进入“生活化例子补讲 + 第 2 题”，不需要学生手动回课程页找资源。",
          nextSteps: [
            "补讲：海风与陆风类比",
            "练习：判断低压形成顺序",
            "若通过：切换到课程视频 03 的 04:10",
          ],
        },
      ],
    },
  ],
};
