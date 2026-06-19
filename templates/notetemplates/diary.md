---
template_title: Diary — {{date}}
template_tags: diary
place:
  label: Place
  type: text
mood:
  label: Mood
  type: dropdown(🙂, 🙂 🙂, 🙂 🙂 🙂, 🙂 🙂 🙂 🙂, 🙂 🙂 🙂 🙂 🙂)
---
#### {{#custom_datetime}}dddd, D MMMM YYYY{{/custom_datetime}} — {{place}}

Mood: {{mood}}

