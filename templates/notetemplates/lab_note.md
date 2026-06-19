---
template_title: Laboratory note — {{date}}
template_tags: lab_note
project_name:
  label: Project name
  type: text
date:
  label: Date
  type: date
location:
  label: Location
  type: text
status:
  label: Status
  type: dropdown(planned, running, done)
---
#### {{project_name}} — {{date}}

**Location:** {{location}}  ·  **Status:** {{status}}

### Measurements

| Quantity | Value | Unit |
| -------- | ----- | ---- |
|          |       |      |

### Chemical process

$\ce{Hg^2+ ->[I-] HgI2 ->[I-] [Hg^{II}I4]^2-}$

### Conventions

$C_p[\ce{H2O(l)}] = \pu{75.3 J // mol K}$

