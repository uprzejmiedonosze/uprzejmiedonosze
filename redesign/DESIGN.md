---
name: Urban Integrity
colors:
  surface: '#fcf9f8'
  surface-dim: '#dcd9d9'
  surface-bright: '#fcf9f8'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f6f3f2'
  surface-container: '#f0eded'
  surface-container-high: '#eae7e7'
  surface-container-highest: '#e5e2e1'
  on-surface: '#1c1b1b'
  on-surface-variant: '#3d4944'
  inverse-surface: '#313030'
  inverse-on-surface: '#f3f0ef'
  outline: '#6d7a74'
  outline-variant: '#bccac3'
  surface-tint: '#006b55'
  primary: '#006953'
  on-primary: '#ffffff'
  primary-container: '#008469'
  on-primary-container: '#f5fff9'
  inverse-primary: '#5fdbb8'
  secondary: '#b41f00'
  on-secondary: '#ffffff'
  secondary-container: '#da3615'
  on-secondary-container: '#fffbff'
  tertiary: '#745b00'
  on-tertiary: '#ffffff'
  tertiary-container: '#d0a600'
  on-tertiary-container: '#4f3d00'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#7df8d3'
  primary-fixed-dim: '#5fdbb8'
  on-primary-fixed: '#002018'
  on-primary-fixed-variant: '#005140'
  secondary-fixed: '#ffdad3'
  secondary-fixed-dim: '#ffb4a4'
  on-secondary-fixed: '#3e0500'
  on-secondary-fixed-variant: '#8d1600'
  tertiary-fixed: '#ffe08b'
  tertiary-fixed-dim: '#f1c100'
  on-tertiary-fixed: '#241a00'
  on-tertiary-fixed-variant: '#584400'
  background: '#fcf9f8'
  on-background: '#1c1b1b'
  surface-variant: '#e5e2e1'
typography:
  h1:
    fontFamily: Work Sans
    fontSize: 48px
    fontWeight: '700'
    lineHeight: '1.2'
    letterSpacing: -0.02em
  h2:
    fontFamily: Work Sans
    fontSize: 36px
    fontWeight: '600'
    lineHeight: '1.3'
    letterSpacing: -0.01em
  h3:
    fontFamily: Work Sans
    fontSize: 24px
    fontWeight: '600'
    lineHeight: '1.4'
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: '1.6'
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: '1.6'
  label-caps:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '700'
    lineHeight: '1'
    letterSpacing: 0.05em
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 8px
  xs: 4px
  sm: 12px
  md: 24px
  lg: 48px
  xl: 80px
  container-max: 1200px
  gutter: 24px
---

## Brand & Style

The design system is built for a social activism platform focused on civic responsibility and urban order. The brand personality is **urgent yet professional**, combining the high-stakes nature of community advocacy with the reliability of a systematic reporting tool. The goal is to evoke a sense of agency and collective power in the user.

The design style follows a **Corporate / Modern** aesthetic with a lean toward **Minimalism**. It prioritizes information density and clear hierarchy through the use of significant whitespace and a structured grid. Visual interest is generated through high-contrast accents and subtle depth, ensuring the platform feels like a trustworthy utility rather than a casual blog.

## Colors

The color palette uses high-visibility signals to denote action and status. 
- **Primary (#00a181):** A vibrant green used for positive actions, success states, and primary navigation elements. It represents "the solution" and growth.
- **Secondary (#f14624):** A bright red reserved for urgent alerts, critical calls to action (CTAs), and highlighting the parking issues themselves.
- **Tertiary (#ffcc00):** A bright yellow used for secondary CTAs, warning states, and informational highlights to draw the eye without the urgency of red.
- **Neutrals:** A range of grays from an off-white background (#f9f9f9) to a deep charcoal (#1a1a1a) for text ensure maximum legibility and a clean atmosphere.

## Typography

This design system utilizes **Work Sans** for headlines to provide a sturdy, professional, and slightly industrial feel that matches the urban context. For body text and interface labels, **Inter** is used for its exceptional readability at various sizes and neutral, functional tone. 

The type scale is generous, with a focus on clear vertical rhythm. High-level headings use a bold weight and slight negative letter spacing to feel "tighter" and more modern, while body text remains airy and accessible.

## Layout & Spacing

The layout is built on a **12-column fixed grid** for desktop, centered within the viewport. Spacing follows an 8px baseline rhythm to ensure consistency across all components and viewports.

Key layout principles:
- **Whitespace:** Use the `xl` spacing unit to separate major sections of content, mirroring the informational style of the reference images.
- **Scanning:** Information is grouped in clear blocks with consistent internal padding (`md`).
- **Alignment:** Content should predominantly be left-aligned to support rapid scanning of text-heavy informational sections.

## Elevation & Depth

To maintain a modern and clean aesthetic, depth is used sparingly. This design system employs **Ambient Shadows** and **Tonal Layers** to create hierarchy:

- **Level 0 (Base):** Off-white background (#f9f9f9) used for the main canvas.
- **Level 1 (Cards/Surface):** Pure white (#ffffff) surfaces with a subtle, very soft shadow (0px 4px 20px rgba(0,0,0,0.05)) to distinguish content containers from the background.
- **Level 2 (Interaction):** When hovering over interactive cards or buttons, the shadow deepens slightly and the element may shift 2px upward to provide tactile feedback.
- **Level 3 (Overlays):** Modals and dropdowns use a more pronounced shadow (0px 10px 30px rgba(0,0,0,0.1)) and a background backdrop blur for focus.

## Shapes

The shape language is **Rounded**, striking a balance between the rigidness of city infrastructure and the approachability of a community tool. 

- **Standard Elements:** Buttons and input fields use a 0.5rem (8px) corner radius.
- **Containers:** Content cards and informational blocks use a 1rem (16px) corner radius.
- **Icons/Avatars:** Badges and user avatars use a fully circular (pill) shape to differentiate them from functional UI elements.

## Components

### Buttons
- **Primary:** Filled with Green (#00a181), white text, bold weight. These are the main "Report" or "Submit" actions.
- **Urgent:** Filled with Red (#f14624), white text. Used for "Join the Protest" or high-priority calls to action.
- **Secondary:** Outlined with a 2px stroke in the primary or neutral color.

### Cards
Cards are the primary way to organize information (as seen in Image 1). They feature a white background, the Level 1 shadow, and 24px internal padding. They should include a subtle top-border accent in one of the brand colors to categorize content.

### Inputs
Text fields are clean with a light gray border (#e0e0e0) that turns Primary Green on focus. Labels are always positioned above the input for clarity.

### Progress & Stats
Drawing from Image 2, numerical statistics and progress indicators should be prominent. Use the Primary Green for positive metrics and Secondary Red for "Issues Found" or "Tickets Unresolved."

### Chips
Small, pill-shaped tags used for status (e.g., "In Progress," "Resolved"). Use low-opacity tints of the brand colors (e.g., 10% green background with green text) to avoid overwhelming the user visually.