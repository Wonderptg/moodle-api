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

Mixed-category smoke checked on `2026-05-18`:

- categoryids: `619`, `616`
- tagid: `461`
- tagname: `必修第一章集合与常用逻辑用语`
- `619` indexed question_count: `168`
- `616` indexed question_count: `50`
- dry-run with `--category-id 619 --category-id 616 --tag-id 461 --count 6 --random` returned `ok=true`
- observed category_counts: `{"619": 6}`

Meaning: repeated `--category-id` is supported as a source pool, and tag filtering works across the pool. It does not guarantee category quotas. If the user needs “619 must provide N questions and 616 must provide M questions”, use `--category-spec`.

Quota syntax:

- Use `--category-spec 619:3 --category-spec 616:2` when the user needs exact per-category counts in one quiz.
- When `--category-spec` is present, the total count is the sum of the specs and overrides `--count`.

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

6. Optional mixed-category preview.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --dry-run --force quiz create-practice \
  --idempotency-key codex-mixed-category-tagid-preview-20260518-1 \
  --course-id 116 \
  --category-id 619 \
  --category-id 616 \
  --tag-id 461 \
  --count 6 \
  --random \
  --allow-partial \
  --title "Codex多分类标签组卷预览-第一章"
```

Observed result:

```text
ok=true
selection_mode=random_category
created_count=6
tag_ids=[461]
category_counts={"619": 6}
```

This proves the command accepts multiple categories and a real Moodle tag id. It also proves this is not quota-based mixed assembly.

7. Optional quota-based mixed-category preview.

```bash
python3 scripts/moodle_cli.py --profile dzexam --json --dry-run --force quiz create-practice \
  --idempotency-key codex-category-spec-preview-20260518-1 \
  --course-id 116 \
  --category-spec 619:3 \
  --category-spec 616:2 \
  --tag-id 461 \
  --random \
  --allow-partial \
  --title "Codex分类配额标签组卷预览-第一章"
```

Expected result:

```text
ok=true
selection_mode=random_category
requested_count=5
category_specs=[{"categoryid":619,"requested_count":3,"selected_count":3},{"categoryid":616,"requested_count":2,"selected_count":2}]
```
