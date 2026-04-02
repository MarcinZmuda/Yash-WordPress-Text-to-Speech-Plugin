# Changelog — Yash

**Author:** Marcin Żmuda · [marcinzmuda.pl](https://marcinzmuda.pl)

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [1.0.0] — 2025

### Added
- Google Wavenet TTS player with Polish language support
- MP3 caching — audio generated once, served from disk on repeat visits
- Paragraph highlighting — active paragraph highlighted and scrolled into view
- Seek — click progress bar to jump to any position in the article
- Playback speed control: 0.75× to 2.0× in the player
- Listening statistics: plays, completions, listening time, unique listeners
- Daily chart and top articles ranking in the stats dashboard
- Article + AudioObject schema with `duration` field (ISO 8601)
- Speakable schema for Google Assistant / voice search
- Adaptive player design — inherits colors from the active WordPress theme
- Automatic dark mode support via `prefers-color-scheme`
- Cache auto-invalidation when a post is updated or deleted
- Prefetching of upcoming audio chunks for seamless playback
- Admin panel with cache size overview and one-click cache clear
