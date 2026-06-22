Public/auth images:

- Background path: church image.png
- Optional logo path: assets/img/san-lorenzo-logo.png

How the system uses these images:

- `church image.png` is the full-screen background for login, registration, and password recovery.
- `assets/img/san-lorenzo-logo.png` replaces the default church icon on auth cards when the file exists.

Recommended logo format:

- PNG with transparent background
- Square composition, at least 512x512

If you prefer a different filename, update `assets/css/style.css` and the auth page background URLs accordingly.
