# Design System

Nexa TradeBot uses a compact, dark financial-terminal language designed for long sessions and dense information.

## Tokens

| Purpose | Value |
|---|---|
| App background | `#07101b` |
| Sidebar | `#0a1421` |
| Panel | `#0d1826` |
| Border | `#1e3044` |
| Primary text | `#dce7f6` |
| Muted text | `#7890aa` |
| Buy / profit / healthy | `#27d7a1` |
| Sell / loss / danger | `#ff5e6c` |
| Market / navigation | `#3794ff` |
| AI | `#a879ff` |
| Warning / pending | `#f5a524` |

Typography uses Inter for interface text and JetBrains Mono for prices, P/L, scores, and account values. Cards use 6–7px radii and one-pixel borders, not glass effects. Spacing is based on 4px increments with compact 8–15px panel padding.

## Components

Badges always combine text with color. Tables retain semantic headers and scroll horizontally at narrow widths. Buttons have visible focus rings and explicit simulation verbs. Reusable primitives include page and panel headers, metric cards, status/environment/direction badges, P/L formatting, AI and risk gauges, filter bars, data tables, charts, state views, and confirmation dialogs.

## Responsive behavior

The 1920/1440/1366 desktop layouts prioritize data density. At 1100px, navigation collapses and grids reduce columns. At 760px, navigation becomes a drawer, content grids stack, forms use two columns, and tables remain accessible through horizontal scrolling.

Animations are limited to loading and connection-status affordances. Profit/loss meaning is never communicated by color alone; labels and signs remain present.
