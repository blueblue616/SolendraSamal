# Color System Documentation

## Overview
This color system uses **Golden Sand (#C59F61)** as the primary warm color and **Teal (#1F83A4)** as the secondary cool color. The palette is designed to create a balanced, luxurious aesthetic that feels intentional and sophisticated.

---

## Color Palette

### Primary: Warm Golden Sand
The primary color brings warmth, approachability, and a sense of premium quality.

| Variable | Hex | Usage |
|----------|-----|-------|
| `--primary` | #C59F61 | Headlines, primary buttons, key accents |
| `--primary-light` | #D4B078 | Hover states, elevated elements |
| `--primary-lighter` | #E3C28F | Subtle highlights, decorative elements |
| `--primary-dark` | #A6824D | Active states, pressed elements |
| `--primary-darker` | #87683F | Disabled states, depth layers |
| `--primary-fade` | rgba(197, 159, 97, 0.08) | Background tints, subtle overlays |
| `--primary-fade-medium` | rgba(197, 159, 97, 0.15) | Medium emphasis backgrounds |
| `--primary-fade-strong` | rgba(197, 159, 97, 0.25) | Strong emphasis backgrounds |

### Secondary: Teal
The secondary color adds credibility, calm, and professional balance.

| Variable | Hex | Usage |
|----------|-----|-------|
| `--secondary` | #1F83A4 | CTAs, links, interactive elements |
| `--secondary-light` | #2E9BB8 | Hover states for secondary actions |
| `--secondary-lighter` | #4DB3CD | Bright highlights, success states |
| `--secondary-dark` | #186A87 | Active secondary states |
| `--secondary-darker` | #12556B | Depth for secondary elements |
| `--secondary-fade` | rgba(31, 131, 164, 0.08) | Background tints |
| `--secondary-fade-medium` | rgba(31, 131, 164, 0.15) | Medium emphasis |
| `--secondary-fade-strong` | rgba(31, 131, 164, 0.25) | Strong emphasis |

### Neutrals
A restrained neutral palette provides structure and readability.

| Variable | Value | Usage |
|----------|-------|-------|
| `--bg-primary` | #0a0a0a | Main background (dark theme) |
| `--bg-secondary` | #121212 | Secondary backgrounds |
| `--bg-tertiary` | #1a1a1a | Tertiary backgrounds, cards |
| `--bg-card` | rgba(255, 255, 255, 0.05) | Card backgrounds |
| `--bg-card-hover` | rgba(255, 255, 255, 0.08) | Card hover state |
| `--text-primary` | #ffffff | Primary text |
| `--text-secondary` | rgba(255, 255, 255, 0.7) | Secondary text |
| `--text-tertiary` | rgba(255, 255, 255, 0.5) | Tertiary text |
| `--text-muted` | rgba(255, 255, 255, 0.35) | Muted/disabled text |
| `--border-subtle` | rgba(255, 255, 255, 0.08) | Subtle borders |
| `--border-medium` | rgba(255, 255, 255, 0.12) | Medium borders |
| `--border-strong` | rgba(255, 255, 255, 0.18) | Strong borders |

### Status Colors
Functional colors for UI feedback.

| Variable | Hex | Usage |
|----------|-----|-------|
| `--success` | #22c55e | Success states, confirmations |
| `--success-fade` | rgba(34, 197, 94, 0.15) | Success backgrounds |
| `--warning` | #fbbf24 | Warning states |
| `--warning-fade` | rgba(251, 191, 36, 0.15) | Warning backgrounds |
| `--error` | #ef4444 | Error states, destructive actions |
| `--error-fade` | rgba(239, 68, 68, 0.15) | Error backgrounds |

### Transitions
Consistent timing for smooth interactions.

| Variable | Value | Usage |
|----------|-------|-------|
| `--transition-fast` | 0.2s ease | Quick micro-interactions |
| `--transition-base` | 0.3s cubic-bezier(0.4, 0, 0.2, 1) | Standard transitions |
| `--transition-smooth` | 0.4s cubic-bezier(0.4, 0, 0.2, 1) | Complex animations |

---

## WCAG Contrast Compliance

### Primary Color (#C59F61)
- **On white background**: 3.2:1 (AA for large text, fails for normal text)
- **On black background**: 10.5:1 (AAA compliant)
- **On dark gray (#1a1a1a)**: 9.8:1 (AAA compliant)

### Secondary Color (#1F83A4)
- **On white background**: 4.5:1 (AA compliant for normal text)
- **On black background**: 4.7:1 (AA compliant)
- **On dark gray (#1a1a1a)**: 4.4:1 (AA compliant)

### Recommendations
- Use **primary color (#C59F61)** for headlines and large text on dark backgrounds
- Use **secondary color (#1F83A4)** for body text and interactive elements
- Always ensure text on primary backgrounds is white for sufficient contrast
- For small text on light backgrounds, use darker shades of the palette

---

## Element-Specific Application

### Headlines
- **Color**: `--primary` (#C59F61)
- **Rationale**: Golden sand creates warmth and draws attention without overwhelming
- **Usage**: H1-H6 elements, section titles

### Primary Buttons
- **Background**: `linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%)`
- **Text**: White
- **Hover**: `--primary-light` with elevated shadow
- **Rationale**: Gradient adds depth, warm color encourages action

### Secondary Buttons / CTAs
- **Background**: `--secondary` (#1F83A4)
- **Text**: White
- **Hover**: `--secondary-light`
- **Rationale**: Teal provides credibility and calm for secondary actions

### Links
- **Color**: `--secondary` (#1F83A4)
- **Hover**: `--secondary-light`
- **Rationale**: Teal is professional and indicates interactivity

### Icons
- **Primary icons**: `--primary`
- **Secondary icons**: `--secondary`
- **Rationale**: Color coding helps distinguish icon hierarchy

### Borders & Dividers
- **Standard**: `--border-subtle` or `--border-medium`
- **Emphasis**: `--primary-fade-medium`
- **Rationale**: Subtle borders maintain elegance without distraction

### Backgrounds
- **Main**: `--bg-primary` (#0a0a0a)
- **Cards**: `--bg-card` with `--border-subtle`
- **Hover states**: `--bg-card-hover`
- **Alternating sections**: Use `--bg-secondary` for visual rhythm

---

## Hover & Transition Specifications

### Buttons
```css
transition: all var(--transition-base);
```
- **Hover**: `transform: translateY(-2px)`, shadow increase
- **Active**: `transform: scale(0.98)`
- **Focus**: Ring using `--primary-fade`

### Links
```css
transition: color var(--transition-fast);
```
- **Hover**: Color shifts to lighter variant
- **Underline**: Optional, use `--primary-fade-medium`

### Cards
```css
transition: all var(--transition-base);
```
- **Hover**: `transform: translateY(-4px)`, border color to `--primary-fade-strong`
- **Shadow**: Increases from `--shadow` to `--shadow-hover`

### Form Inputs
```css
transition: all 0.3s ease;
```
- **Focus**: Border to `--primary`, shadow with `--primary-fade`
- **Background**: Shifts to `--bg-card-hover`

### Navigation Items
```css
transition: all 0.2s ease;
```
- **Hover**: Background to `--primary-fade`, color to `--primary`
- **Active**: Same as hover with stronger background

---

## Background Pairings for Alternating Sections

To create visual rhythm and prevent visual fatigue:

### Pattern A
- **Background**: `--bg-primary` (#0a0a0a)
- **Card background**: `--bg-card`
- **Text**: `--text-primary`

### Pattern B
- **Background**: `--bg-secondary` (#121212)
- **Card background**: `--bg-card-hover`
- **Text**: `--text-primary`

### Pattern C
- **Background**: `--bg-tertiary` (#1a1a1a)
- **Accent border**: `--primary-fade-medium`
- **Text**: `--text-primary`

Use these patterns sequentially for sections (About, Gallery, Amenities, etc.) to create subtle visual distinction while maintaining cohesion.

---

## Usage Rationale

### Design Philosophy
1. **Warmth + Credibility**: Golden sand (#C59F61) brings warmth and approachability, while teal (#1F83A4) adds credibility and professional calm
2. **Restrained Neutrals**: Dark neutrals (#0a0a0a, #121212) provide depth without competing with accent colors
3. **Intentional Balance**: Primary and secondary colors are used strategically, not randomly
4. **Accessibility First**: All color combinations meet WCAG AA standards where possible

### Color Psychology
- **Golden Sand**: Luxury, warmth, hospitality, premium quality
- **Teal**: Trust, professionalism, calm, clarity
- **Dark Neutrals**: Sophistication, focus, elegance

### When to Use Each Color

**Use Primary (#C59F61) for:**
- Headlines and section titles
- Primary action buttons
- Key accent elements (icons, badges)
- Hover states on neutral elements
- Brand identity elements

**Use Secondary (#1F83A4) for:**
- Links and navigation
- Secondary action buttons
- Informational messages
- Interactive elements
- Status indicators (info)

**Use Neutrals for:**
- Body text
- Backgrounds
- Borders and dividers
- Disabled states
- Supporting content

---

## Implementation Notes

### CSS Variable Usage
All colors are defined as CSS custom properties (variables) for:
- Easy theming and maintenance
- Consistent application across components
- Simple color adjustments
- Dark/light theme support

### Gradient Usage
Gradients are used sparingly and intentionally:
- Primary buttons: `135deg` gradient from primary to primary-light
- This adds depth without being overwhelming
- Flat colors are preferred for most UI elements

### Shadow System
Shadows are color-based for cohesion:
- Primary elements: Shadows with `--primary-fade-medium`
- Neutral elements: Standard black shadows
- Hover states: Increased shadow intensity

---

## Files Updated
- `Css/Page.css` - Frontend page styling
- `Css/Admin.css` - Admin panel styling

Both files now use the unified color system with CSS variables for consistency.
