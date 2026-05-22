# Moodle Agent Tool Use Cases

This file gives another AI the shortest path from user intent to the right
Moodle tool call. Prefer domain tools over raw CLI when the plugin is installed.

## Inspect Searched Questions With Answers And Analysis

User intent:

```text
Find 20 questions from this course/category and show me the question text,
options, correct answers, and analysis.
```

Best tool:

```json
{
  "tool": "moodle_questionbank",
  "arguments": {
    "action": "search_details",
    "profile": "dzexam",
    "params": {
      "courseId": 116,
      "categoryId": 619,
      "tagIds": [461],
      "limit": 20,
      "showCorrection": true,
      "includeFeedback": true
    }
  }
}
```

Read results from:

```text
OpenClaw: details.data.data.questions
Codex MCP: structuredContent.data.data.questions
```

Each question item includes the lightweight search metadata plus:

- `content.text_html`
- `content.text_plain`
- `answers`
- `answers[].feedback_html`
- `answers[].feedback_plain`
- `correct_labels`
- `explanation.general_feedback_html`
- `explanation.correct_feedback_html`
- `explanation.partially_correct_feedback_html`
- `explanation.incorrect_feedback_html`
- `detail_available`

Use this when the user explicitly needs answers, answer checking, or Moodle
feedback/analysis for a modest page of results. Keep `limit` around 20. The CLI
enforces a hard max of 50 because rendering answers and feedback pulls heavier
HTML than a normal search.

Use `showCorrection=false` / `--hide-correction` when the user should not see
answers or analysis. Correction hiding blanks `correct_labels`, answer
correctness, fractions, answer feedback, and question explanation fields.

CLI equivalent:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json \
  questions search-details \
  --course-id 116 \
  --category-id 619 \
  --tag-id 461 \
  --limit 20
```

## Search Cheaply, Then Render Known Ids

Use this when the user is browsing or filtering first, and may not need answers
for every result.

```json
{
  "tool": "moodle_questionbank",
  "arguments": {
    "action": "search",
    "profile": "dzexam",
    "params": {
      "courseId": 116,
      "categoryId": 619,
      "query": "chapter 1",
      "limit": 20
    }
  }
}
```

Then render only selected ids:

```json
{
  "tool": "moodle_questionbank",
  "arguments": {
    "action": "render_html",
    "profile": "dzexam",
    "params": {
      "questionIds": [334898, 334896],
      "showCorrection": true,
      "includeFeedback": true
    }
  }
}
```

CLI equivalent:

```bash
python3 scripts/moodle_cli.py --profile dzexam --json \
  questions search --course-id 116 --category-id 619 --query "chapter 1" --limit 20

python3 scripts/moodle_cli.py --profile dzexam --json \
  questions render-html 334898 334896 --show-correction --include-feedback
```

## Choosing The Action

- `search`: fast list of ids and metadata. Use for broad browsing, counting,
  filtering, or picking ids.
- `render_html`: rich detail for known question ids. Use after search,
  random selection, or quiz inspection. Add `showCorrection=true` and
  `includeFeedback=true` when answers and analysis are needed.
- `search_details`: one searched page plus rendered details and correction
  markers. Use when the user asks to inspect question content, answers, and
  analysis together.

Do not use `moodle_api.call` for these flows. The domain tool already covers
them and keeps the command manifest, CLI profile handling, and safety rules in
one place.
