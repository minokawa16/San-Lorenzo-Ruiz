# UI Revision Specification — AI Chatbot Widget Header

**Component:** Parish AI Assistant chat widget header (`templates/footer.php` / global chatbot component)  
**Status:** Implemented & Verified  
**Date:** September 17, 2026  

---

## 1. Objective
Refine the header of the global floating AI Chatbot widget to be cleaner, more compact, and vertically balanced by removing status subtitles and shortening the brand title.

---

## 2. Scope of Changes

### 2.1 Title Shortening
- **Previous:** `TUGON Parish Guide`
- **Updated:** `TUGON`
- **Typography:** Serif (`Playfair Display`, `Cinzel`, Georgia), 1.2rem, font-weight 700, letter-spacing 0.5px.

### 2.2 Status Subtitle Removal
- Removed the `<span id="aiAssistantStatus">...</span>` line ("Online & Ready / Online & ready to help") from the header markup.
- Removed all CSS rules styling the subtitle text (`.ai-assistant-panel-identity span`).

### 2.3 Status Indicator Dot Removal
- Removed the green online-status indicator dot (`.ai-assistant-status-dot`) from the markup and CSS.
- Cleaned up JavaScript status updater methods (`setTyping()`, `setHealthStatus()`) to eliminate orphaned DOM queries and innerHTML mutations.

### 2.4 Vertical Re-centering & Single-Row Layout
- Updated `.ai-assistant-panel-identity` from vertical column layout (`flex-direction: column`) to a vertically centered flex container (`display: flex; align-items: center;`).
- Adjusted title `line-height: 1 !important; margin: 0 !important;` so the avatar squircle (36px), title ("TUGON"), and controls (Clear, Minimize, Close - 28px) align seamlessly on a single horizontal center axis.

---

## 3. Retained Elements
- Header container styling (dark sacred green gradient `linear-gradient(135deg, #344536 0%, #263628 100%)`, gold bottom border `#C9A646`).
- Avatar mark squircle with church icon (`fa-church`).
- Control buttons:
  - Reset / Clear conversation (`fa-rotate-left`)
  - Minimize (`fa-minus`)
  - Close (`fa-xmark`)
- Chat body, suggestion chips, messages, auto-scroll, and input form.

---

## 4. Affected Files
- `templates/footer.php` — Header CSS, HTML markup, and JavaScript runtime.
