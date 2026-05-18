# Use Cases

## Create A Practice Quiz

Use this when the user asks to create a Moodle practice quiz from existing question-bank questions.

Real smoke example created on `2026-05-18`:

- courseid: `116`
- categoryid: `619`
- query: `第一章`
- selected tagid: `461`
- selected tagname: `必修第一章集合与常用逻辑用语`
- created quizid: `857`
- created cmid: `12250`
- created url: `https://dzexam.cn/mod/quiz/view.php?id=12250`

1. Sync the tag index for the source question category.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json questions tags-sync \
  --course-id 116 \
  --category-id 619
```

2. Find the real Moodle tag id.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json questions tags \
  --course-id 116 \
  --category-id 619 \
  --query "第一章" \
  --limit 5
```

Use `data.tags[0].tagid` only after checking the returned `tagname` and `question_count`.

Observed result:

```text
tagid=461
tagname=必修第一章集合与常用逻辑用语
question_count=168
```

3. Preview the question pool with pagination.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json questions search \
  --course-id 116 \
  --category-id 619 \
  --tag-id 461 \
  --limit 20 \
  --offset 0
```

Observed first page included question ids `334898`, `334896`, and `61581`.

4. Dry-run the quiz creation.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --dry-run --force quiz create-practice \
  --idempotency-key codex-real-practice-preview-20260518-140044 \
  --course-id 116 \
  --category-id 619 \
  --tag-id 461 \
  --count 3 \
  --random \
  --allow-partial \
  --title "Codex真实组卷测试-第一章集合-预览"
```

Check `ok=true`, `data.created_count`, `data.tag_ids`, `data.questions`, and `data.selection_mode`.

Observed dry-run result:

```text
ok=true
selection_mode=random_category
created_count=3
tag_ids=[461]
```

5. Create the real quiz.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --force quiz create-practice \
  --idempotency-key codex-real-practice-create-20260518-140044 \
  --course-id 116 \
  --category-id 619 \
  --tag-id 461 \
  --count 3 \
  --random \
  --allow-partial \
  --title "Codex真实组卷测试-第一章集合-20260518"
```

Record `data.quizid`, `data.quiz_cmid`, and `data.url`.

Observed real creation result:

```text
ok=true
quizid=857
quiz_cmid=12250
url=https://dzexam.cn/mod/quiz/view.php?id=12250
visible=false
random_tagids=[461]
```

Do not add `--visible` unless the user explicitly asks to publish the quiz to students.
