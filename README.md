# HideSensitive

HideSensitive is a MediaWiki extension that allows wiki editors to mark images, audio, and video files as sensitive and hide them behind a consent-based overlay before they are displayed.

Instead of showing the media immediately, HideSensitive displays a neutral placeholder with a crossed-eye icon, a **“Sensitive Content”** warning, an optional description, and a button that allows the user to reveal the content if they choose to do so.

The media is not loaded until the user clicks the **Show** button.

---

## Features

- Hide sensitive media behind a user-controlled overlay  
- Works with images, audio, and video  
- Supports:
  - Thumbnails (`thumb`)
  - Inline files
  - File description pages (`File:` namespace)
  - MultimediaViewer (full-screen media viewer)
  - TimedMediaHandler (video and audio playback, including VideoJS)
- Optional descriptions for sensitive content
- Customizable button text and colors
- User and group-based bypass permissions
- Namespace restrictions
- Prevents preloading of sensitive images or videos before consent

---

## Usage

You can mark a file as sensitive directly in wikitext:

`wiki
[[File:Example.jpg|thumb|200px|sensitive=true]]
`

With a description:

`wiki
[[File:Example.jpg|thumb|sensitive=true|description=Graphic violence]]
`

You can also mark a file as sensitive globally from its file description page using a template or metadata (depending on configuration).

Local flags always override global file settings.

---

## Installation

See: link
## Author

Developed by **BZPN**
