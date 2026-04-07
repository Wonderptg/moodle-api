# Timetable, Today, and Session Architecture

## Goal

Define the product shape for an AI-first learning frontend that replaces most student-facing Moodle navigation with:

1. a stable timetable shell
2. an AI-generated daily plan
3. a guided learning session page

The key idea is:

- `课程蓝图 Blueprint` defines the standard learning path
- `学生分支 Branch` stores each learner's current state
- `AI 补丁 Patch` adjusts what to do today without rewriting the whole course

This keeps teaching structure stable while still letting the agent personalize execution.

## Product thesis

Do not let the AI freely invent a new learning plan every day.

Instead:

1. teachers or the product define a stable timetable template
2. each course is expressed as a standard blueprint
3. the student's mastery and progress live in a branch state
4. the AI generates small, explainable patches on top of that base

The frontend should therefore have three main surfaces:

1. `课表页 Timetable`
2. `今日页 Today`
3. `上课页 Session`

## Why three surfaces are necessary

### Timetable page

Purpose:

- provide a stable daily and weekly skeleton
- answer "which subjects exist in my day"
- reduce agent randomness

What belongs here:

- recurring time slots
- subject allocation
- slot type: main lesson, practice, self-study, review
- fixed teacher or course association when known

What the AI may change:

- which concrete unit fills a slot
- whether a slot becomes review instead of new content
- whether a self-study slot becomes weak-point remediation

What the AI should not freely change:

- how many major subjects exist in a day
- the overall order of the day
- the existence of self-study slots
- the student's maximum study load

### Today page

Purpose:

- translate the stable timetable into today's concrete work
- answer "what exactly am I doing today"

What belongs here:

- today's goal
- today's selected units
- today's patch summary
- slot-by-slot task list
- due items and weak-point carryovers

This page is where the AI patch becomes visible.

### Session page

Purpose:

- execute exactly one learning block at a time
- answer "what do I do right now"

What belongs here:

- current lesson objective
- chat-first tutor experience
- video, notes, quiz, analysis, and next-step blocks
- progress within the current slot

This is the main runtime page.

## Core model

### 1. Course blueprint

The blueprint is the canonical teaching path for a course.

It should be built from real Moodle structure, not invented by the agent.

Example course types already visible in this repo:

- `视频课` such as `course 26 / 2026单招数学课-任老师`
- `综合课` such as `course 92 / 2026四类综合`
- `题库课`
- `quiz-heavy` courses
- `self-study` slots that are not tied to one single Moodle course

Each course should map to a small number of archetypes:

1. `video_lesson`
2. `question_bank`
3. `mixed_course`
4. `mock_exam`
5. `self_study`

For example, a `video_lesson` unit should look like:

```json
{
  "unit_id": "math26-1.1",
  "course_id": 26,
  "title": "集合的概念",
  "archetype": "video_lesson",
  "resources": {
    "video": "视频课-1.1集合的概念",
    "slides": "课件-1.1 集合的概念",
    "quiz": "课后测试-1.1 集合的概念"
  },
  "estimated_minutes": 45,
  "mastery_rule": {
    "quiz_accuracy_min": 0.8
  }
}
```

### 2. Student branch

The branch is not a copy of the blueprint.

It is a per-student overlay that stores:

- current unit
- completed units
- mastery by topic
- weak topics
- pacing
- available study time
- recent failures
- preferred slot intensity

The branch should be updated after every meaningful study action.

### 3. AI patch

The patch is the daily adjustment layer.

The AI should output operations like:

- `insert`
- `delay`
- `split`
- `replace`
- `review`
- `downgrade`
- `advance`

Examples:

- insert a weak-point review into tonight's self-study
- delay tomorrow's new math unit because today's quiz accuracy was too low
- replace a new-content slot with error review
- split one 50-minute lesson into two 25-minute blocks

The patch must be explainable in plain language.

### 4. Session run

A session run is the execution record for one slot.

It should store:

- slot id
- selected unit
- start time
- blocks rendered in the session
- quiz result summary
- mastery update
- next-step decision

This is the bridge between frontend UX and long-term branch state.

## Recommended day structure

For this product, a day should not be one long class and should not be a random list of tasks.

A stable default pattern is:

1. `主课精讲`
2. `主课练习`
3. `次主课推进`
4. `练习或题库课`
5. `自习补弱`
6. `收尾总结`

This gives structure without forcing all students into the same exact content.

## Example: one real student day

Using real courses already present in the upgraded rehearsal environment:

- `course 26 / 2026单招数学课-任老师`
- `course 92 / 2026四类综合`

Example timetable shell:

| Time | Slot Type | Subject | Default rule |
| --- | --- | --- | --- |
| 08:00-08:45 | Main lesson | Math | hardest new content goes here |
| 09:00-09:40 | Practice | Math | immediate quiz or exercise after main lesson |
| 14:00-14:45 | Secondary lesson | Comprehensive | medium-intensity progress |
| 16:00-16:40 | Practice bank | Comprehensive or question bank | expose weak points |
| 19:00-19:40 | Self-study | Dynamic | weak-point repair or wrong-question review |
| 20:00-20:25 | Close | General | summary, calendar sync, tomorrow preview |

Example daily patch for a student weak in math functions:

1. slot `08:00-08:45`
   - select `course 26`
   - use unit `函数的概念`
2. slot `09:00-09:40`
   - use matching `课后测试`
   - if accuracy < 60%, do not unlock next unit
3. slot `14:00-14:45`
   - use `course 92`
   - continue today's comprehensive topic
4. slot `19:00-19:40`
   - convert self-study into math remediation
   - review wrong answers from the morning quiz
5. slot `20:00-20:25`
   - summarize completion
   - sync tomorrow adjustments into calendar

## What a session should feel like

The session page should be chat-first, but not chat-only.

Within one slot, the AI should run a small loop:

1. briefing
2. teach
3. checkpoint
4. practice
5. assess
6. adapt
7. close

For example, for `course 26 / 1.1 集合的概念`:

1. explain today's objective
2. show or launch the matching video lesson
3. pause for a short check question
4. open the matching quiz or practice
5. identify whether the learner confused concepts
6. decide whether to continue or remediate
7. write the result back into branch state

## Page-level information architecture

### Timetable page

Primary components:

1. weekly timetable grid
2. slot template editor
3. subject intensity labels
4. self-study slot policy
5. teacher-defined defaults

Key actions:

- lock a slot
- mark a slot as AI-adjustable
- set daily max load
- choose which subjects can occupy self-study

### Today page

Primary components:

1. today's objective card
2. patch summary card
3. slot agenda list
4. weak-point carryover card
5. due-soon card
6. `Start current slot` action

Key actions:

- accept today's plan
- reduce today's load
- regenerate one slot
- skip one slot with reason

### Session page

Primary components:

1. current slot header
2. tutor chat stream
3. media and card blocks
4. quiz interaction surface
5. mastery and progress sidebar
6. `Complete slot` and `Need more help` actions

Key actions:

- ask a question
- slow down
- switch to example mode
- retry question
- finish this slot
- defer remaining work

## Frontend and agent responsibilities

### The frontend should own

- visual timetable
- daily agenda rendering
- session UX
- slot state transitions
- user controls and confirmations

### The AI should own

- unit selection within allowed slots
- pacing changes
- remediation decisions
- weak-point prioritization
- session block ordering

### Moodle should remain the system of record for

- real course content
- quizzes and attempts
- calendar items
- grades and completion data

## Mapping to current Moodle CLI capability

This architecture fits the tools that already exist in this repo.

### Timetable and Today

Use:

- `courses list`
- `courses outline`
- `activities list`
- `activities due`
- `calendar list`
- `calendar upsert-plan`
- `progress course`

### Session

Use:

- `resources list`
- `quiz list`
- `quiz start`
- `quiz attempt-data`
- `quiz answer`
- `quiz submit-attempt`
- `quiz attempts`
- `questions render-html`

### Branch updates

Use:

- quiz results
- progress snapshots
- grade data
- agent-side session summaries

## V1 recommendation

Do not start with a full planner for every subject.

Start with one stable pattern:

1. one timetable shell
2. one Today page
3. one Session page
4. one main course and one secondary course
5. one self-study remediation slot

Suggested real-course v1:

- Main course: `course 26 / 2026单招数学课-任老师`
- Secondary course: `course 92 / 2026四类综合`

Why this is a good v1:

- it covers both structured video lessons and broader comprehensive study
- it creates a natural need for timetable + patch + session
- it forces the system to handle both new learning and remediation

## Implementation order

1. define timetable slot schema
2. define branch and patch schema
3. design Today page around those schemas
4. keep the current chat/session prototype, but position it as only the Session page
5. connect one real morning math flow on `course 26`
6. connect one real secondary slot on `course 92`
7. write plan changes back to Moodle calendar

## Decision summary

The product should not be:

- a pure chat bot
- a Moodle dashboard replacement
- a Notion-like blank canvas

The product should be:

- a timetable-driven learning system
- with AI-generated daily patches
- and a chat-first execution page for each learning slot
