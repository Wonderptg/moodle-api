function el(tagName, className, text) {
  const node = document.createElement(tagName);
  if (className) node.className = className;
  if (text !== undefined) node.textContent = text;
  return node;
}

function renderMetaChips(metaChips) {
  const root = document.getElementById("session-meta");
  metaChips.forEach((chipText) => {
    root.appendChild(el("div", "chip", chipText));
  });
}

function renderTodayHero(data) {
  const root = document.getElementById("today-hero");
  root.innerHTML = "";
  root.appendChild(el("h3", "hero-title", data.title));
  root.appendChild(el("p", "hero-copy", data.copy));
}

function renderPlan(items) {
  const root = document.getElementById("plan-timeline");
  items.forEach((item, index) => {
    const entry = el("div", `timeline-item${item.active ? " active" : ""}`);
    entry.appendChild(el("div", "timeline-dot", String(index + 1)));
    const content = el("div");
    content.appendChild(el("div", "timeline-title", item.title));
    content.appendChild(el("div", "timeline-desc", item.copy));
    entry.appendChild(content);
    root.appendChild(entry);
  });
}

function renderStreamHeader(stream) {
  document.getElementById("stream-title").textContent = stream.title;
  document.getElementById("stream-subtitle").textContent = stream.subtitle;
  document.getElementById("progress-label").textContent = `${stream.progress}%`;
  document.getElementById("progress-fill").style.width = `${stream.progress}%`;
}

function renderComposer(composer) {
  document.getElementById("composer-status").textContent = composer.status;
  document.getElementById("composer-placeholder").textContent = composer.placeholder;
  const actions = document.getElementById("composer-actions");
  composer.actions.forEach((label) => {
    const button = el("button", "ghost-btn", label);
    button.type = "button";
    actions.appendChild(button);
  });
  const tags = document.getElementById("composer-tags");
  composer.tags.forEach((label) => tags.appendChild(el("div", "tag", label)));
}

function renderRuntimeStatus(items) {
  const root = document.getElementById("runtime-status");
  items.forEach((item) => {
    const card = el("div", "mini-stat-card");
    card.appendChild(el("div", "mini-stat-label", item.label));
    card.appendChild(el("div", "mini-stat-value", item.value));
    root.appendChild(card);
  });
}

function renderAutoNotes(lines) {
  document.getElementById("auto-notes").innerHTML = lines.join("<br>");
}

function renderResources(resources) {
  const root = document.getElementById("resource-list");
  resources.forEach((item) => {
    const card = el("div", "resource-item");
    card.appendChild(el("div", "resource-title", item.title));
    card.appendChild(el("div", "resource-desc", item.copy));
    root.appendChild(card);
  });
}

function createBlockHeader(title, badge) {
  const head = el("div", "card-head");
  head.appendChild(el("div", "card-title", title));
  head.appendChild(el("div", "chip", badge));
  return head;
}

function renderVisualBlock(block) {
  const card = el("section", "lesson-card visual-card");
  card.appendChild(createBlockHeader(block.title, block.badge));
  const body = el("div", "card-body");
  const svgWrap = el("div", "visual-svg");
  svgWrap.innerHTML = block.svg;
  body.appendChild(svgWrap);
  const notes = el("div", "knowledge-grid");
  block.notes.forEach((item) => notes.appendChild(el("div", "knowledge-card", item)));
  body.appendChild(notes);
  card.appendChild(body);
  return card;
}

function renderPillsBlock(block) {
  const wrap = el("div", "hint-strip");
  block.items.forEach((item) => wrap.appendChild(el("div", "hint-pill", item)));
  return wrap;
}

function renderQuizBlock(block) {
  const card = el("section", "lesson-card quiz-card");
  card.appendChild(createBlockHeader(block.title, block.badge));
  const body = el("div", "card-body");
  body.appendChild(el("p", "ai-copy", block.question));
  const options = el("div", "options");
  block.options.forEach((item) => {
    const option = el("div", `option${item.active ? " active" : ""}`);
    option.appendChild(el("div", "option-mark", item.key));
    option.appendChild(el("div", "", item.text));
    options.appendChild(option);
  });
  body.appendChild(options);
  card.appendChild(body);
  return card;
}

function renderAnalysisBlock(block) {
  const card = el("section", "lesson-card analysis-card");
  card.appendChild(createBlockHeader(block.title, block.badge));
  const body = el("div", "card-body");
  body.appendChild(el("p", "ai-copy", block.summary));
  const metrics = el("div", "analysis-metrics");
  block.metrics.forEach((item) => {
    const metric = el("div", "metric");
    metric.appendChild(el("div", "metric-value", item.value));
    metric.appendChild(el("div", "metric-label", item.label));
    metrics.appendChild(metric);
  });
  body.appendChild(metrics);
  card.appendChild(body);
  return card;
}

function renderTransitionBlock(block) {
  const card = el("section", "lesson-card transition-card");
  card.appendChild(createBlockHeader(block.title, block.badge));
  const body = el("div", "card-body");
  body.appendChild(el("p", "ai-copy", block.summary));
  const steps = el("div", "transition-list");
  block.nextSteps.forEach((item, index) => {
    const step = el("div", "transition-item");
    step.appendChild(el("div", "transition-index", String(index + 1)));
    step.appendChild(el("div", "transition-copy", item));
    steps.appendChild(step);
  });
  body.appendChild(steps);
  card.appendChild(body);
  return card;
}

function renderTextBlock(block) {
  return el("p", "ai-copy", block.content);
}

function renderBlock(block) {
  switch (block.type) {
    case "text":
      return renderTextBlock(block);
    case "visual":
      return renderVisualBlock(block);
    case "pills":
      return renderPillsBlock(block);
    case "quiz":
      return renderQuizBlock(block);
    case "analysis":
      return renderAnalysisBlock(block);
    case "transition":
      return renderTransitionBlock(block);
    default:
      return el("pre", "unknown-block", JSON.stringify(block, null, 2));
  }
}

function renderMessages(messages) {
  const root = document.getElementById("lesson-stream");
  messages.forEach((message) => {
    const article = el("article", "message");
    article.appendChild(el("div", `avatar${message.role === "user" ? " user" : ""}`, message.role === "user" ? "你" : "AI"));

    const bubble = el("div", `bubble ${message.role}`);
    const speaker = el("div", "speaker");
    speaker.appendChild(el("span", "speaker-name", message.speaker));
    speaker.appendChild(el("span", "speaker-time", message.time));
    bubble.appendChild(speaker);

    message.blocks.forEach((block) => {
      bubble.appendChild(renderBlock(block));
    });

    article.appendChild(bubble);
    root.appendChild(article);
  });
}

renderMetaChips(LESSON_SESSION.metaChips);
renderTodayHero(LESSON_SESSION.todayHero);
renderPlan(LESSON_SESSION.plan);
renderStreamHeader(LESSON_SESSION.stream);
renderComposer(LESSON_SESSION.composer);
renderRuntimeStatus(LESSON_SESSION.runtimeStatus);
renderAutoNotes(LESSON_SESSION.autoNotes);
renderResources(LESSON_SESSION.resources);
renderMessages(LESSON_SESSION.messages);
