---
paths:
  - 'resources/**'
---

# Resources

## Design system: dark-only, sleek/technical, tokens in @theme
The UI is dark-only (color-scheme: dark, no dark: variants, no light theme). Feel: sleek and technical.
All colors and fonts are Tailwind v4 @theme tokens in resources/css/app.css — background, surface, edge, foreground, muted, accent (cyan), danger. Never use Tailwind default palette colors (gray-*, blue-*, etc.) in components; always the tokens.
Fonts: IBM Plex Sans body, JetBrains Mono for the wordmark and technical labels (uppercase, tracking-wider). Wordmark is "abode_" with the underscore in accent. Sharp radii (rounded-sm controls), 1px edge borders, transition-colors on interactive elements.
