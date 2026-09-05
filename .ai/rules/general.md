---
paths:
  - '**/*'
---

# General

## Project ground rules: narrow scope, no filler, personal-only
This is a personal side project for Jake — never SaaS, never multi-user. Config belongs in code; no administrative UI, ever.
Implement exactly the requested scope; do not anticipate related features.
No filler text anywhere (UI included): no placeholder data, developer assurances, or reminders in finished work — placeholder data only when explicitly requested for visual review.
Comments only for what code cannot express; no restating the code or narrating decisions. Names must be full and intent-revealing, never abbreviated.

## Laravel conventions first; dependencies must earn their weight
Stay true to Laravel conventions unless a deviation genuinely makes things better.
Composer/npm packages are welcome suggestions but must earn their weight: adopt a package only if it serves how this project already wants to operate — never bend the project's approach to fit a package.
