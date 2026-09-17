# UI Revision Specification — AI Chatbot Launcher & Header Icon

**Component:** AI Parish Assistant chat widget — floating launcher button & open-chat header avatar (`templates/footer.php` / global chatbot component)  
**Status:** Implemented & Verified  
**Date:** September 17, 2026  

---

## 1. Objective
Refine the floating AI Chatbot launcher button and expanded chat window header avatar to use a unified, premium **Warm Gold** design with a dark chat-bubble glyph, eliminating visual clutter by removing the text label and status dot.

---

## 2. Scope of Changes

### 2.1 Floating Launcher Button (`.ai-assistant-trigger`)
- **Radial Gold Gradient:** Circular 58px button (`border-radius: 50%`) with `radial-gradient(circle at center, #F7DC8A 0%, #DEB042 55%, #B8861B 100%)`. On hover, lights up with `radial-gradient(circle at center, #FAEC9E 0%, #E5BD52 55%, #C29124 100%)`.
- **Dark Chat-Bubble Glyph:** Replaced the previous icon with a dark chat-bubble glyph (`<i class="fas fa-comment-dots"></i>`), rendered in Cathedral charcoal (`#1C261D`) and centered inside.
- **Removed "PARISH GUIDE" Text Label:** Completely removed the `<span class="ai-assistant-chathead-label">PARISH GUIDE</span>` markup and all associated CSS rules. The launcher is now strictly icon-only.
- **Removed Online-Status Indicator Dot:** Removed the green status indicator dot markup (`.ai-assistant-online-indicator`) and CSS animation rules (`@keyframes aiPulseOnline`), leaving a clean, unencumbered circular button.
- **Soft Drop Shadow:** Replaced harsh halos with a soft multi-layered drop shadow: `box-shadow: 0 6px 18px rgba(0, 0, 0, 0.16), 0 2px 6px rgba(0, 0, 0, 0.08), 0 0 0 1px rgba(184, 134, 27, 0.22)`, elevating the button naturally against any page background.

### 2.2 Header Avatar Treatment (`.ai-assistant-panel-mark`)
- **Consistent Warm Gold Style:** Applied the identical Warm Gold radial gradient (`radial-gradient(circle at center, #F7DC8A 0%, #DEB042 55%, #B8861B 100%)`) and border (`1.5px solid #C49226`).
- **Circular Shape:** Formatted with `border-radius: 50%` (36px circle) matching the launcher button.
- **Matching Glyph:** Centered dark chat-bubble glyph (`<i class="fas fa-comment-dots"></i>`) in `#1C261D`.
- **Absence of Status Dot:** Header avatar is clean and unencumbered, maintaining visual consistency across both collapsed and open states.
- **Vertical Alignment:** Remains flex-centered with the header title (`strong { TUGON }`) and header action controls.

---

## 3. Retained Elements
- **Launcher Position:** Fixed bottom-right corner (`bottom: 24px; right: 24px; z-index: 99999;`).
- **Interactive Behavior:** Drag-and-drop repositioning with boundary clamping and click toggle between open/closed widget states.
- **Header Structure:** Dark Cathedral Green header background (`linear-gradient(135deg, #344536 0%, #263628 100%)`), header title "TUGON", reset/clear button, minimize button, and close button.
- **Chat Body:** Sacred sanctuary aesthetic, suggestion chips, message rendering, live chat input, and send actions.

---

## 4. Affected Files
- `templates/footer.php` — Floating launcher and header avatar markup, styles, and open-state styling.
- `assets/css/responsive-unified.css` — Cleaned up deprecated `.ai-assistant-chathead-label` CSS rules.
- `assets/css/tugon-core.bundle.min.css` — Cleaned up deprecated `.ai-assistant-chathead-label` CSS rules.
