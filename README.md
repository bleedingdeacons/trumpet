# Trumpet

**Announcement management and front-page meeting display for the Unity intergroup suite.**

Trumpet adds an *Announcements* custom post type to WordPress, with an admin interface for creating, scheduling, and managing announcements. It also provides a `[todays_meetings]` shortcode that renders today's meetings on the front page. Trumpet hooks into Unity's container for meeting data and uses Scrutiny's audit tracking for change detection.

**Version:** 2.2.2
**Requires:** WordPress 6.0+ · PHP 8.0+
**Dependencies:** Unity
**License:** MIT (Modified — see [License](#license))
**Author:** [The Bleeding Deacons](mailto:thebleedingdeacons@gmail.com)

---

## Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Requirements](#requirements)
- [Usage](#usage)
  - [Announcements](#announcements)
  - [Today's Meetings Shortcode](#todays-meetings-shortcode)
  - [Settings](#settings)
- [Architecture](#architecture)
- [Building for Production](#building-for-production)
- [License](#license)

---

## Features

- **Announcement custom post type** — a dedicated post type for intergroup announcements with its own admin interface, status workflow, and change tracking.
- **Announcement management** — full CRUD admin with custom columns, status transitions, and audit-logged changes via `AnnouncementChangeTracker`.
- **Today's meetings shortcode** — `[todays_meetings]` renders a formatted list of today's meetings on any page or post, pulled from Unity's meeting repository and sorted by time.
- **Front-page manager** — registers the `[todays_meetings]` shortcode and handles rendering, including meeting names, times, locations, and attendance options with links to individual meeting pages.
- **Meeting repository integration** — uses Unity's `MeetingRepository` and a configurable `MeetingFactory` (defaults to `TsmlMeetingFactory` for TSML integration) to resolve meeting data.
- **Settings page** — configure Trumpet behaviour from *Settings → Trumpet*, including an option to preserve or delete announcement data on uninstall.
- **Dependency container** — registers all services into Unity's container for lazy-loaded, testable architecture.
- **Clean uninstall** — respects the preserve-data setting; when unchecked, removes all announcement posts and related data on plugin deletion.
- **Deactivation cleanup** — flushes rewrite rules and cleans up transient data on deactivation.

---

## Installation

### From a .zip archive

1. Ensure the **Unity** plugin is installed and activated.
2. Download or build the `trumpet.zip` archive.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**.
4. Upload the `.zip` file and click **Install Now**.
5. Activate the plugin.

### Manual installation

1. Clone or copy the `trumpet` directory into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.

Trumpet initialises on the `unity/loaded` action hook, so Unity must be active before Trumpet can function.

---

## Requirements

- **WordPress** 6.0+
- **PHP** 8.0+
- **Unity** plugin — installed and activated
- **TSML** (optional) — if using the `TsmlMeetingFactory` for meeting data

---

## Usage

### Announcements

Navigate to **Announcements** in the WordPress admin sidebar (registered by Trumpet's admin class). From here you can:

- Create, edit, and delete announcements.
- View custom columns showing announcement status and dates.
- Track changes — Trumpet logs when announcements are created, updated, or have their status changed.

### Today's Meetings Shortcode

Add the `[todays_meetings]` shortcode to any page or post. It renders a list of today's meetings with:

- Meeting time
- Meeting name (linked to the meeting page)
- Location or attendance option (in-person, online, hybrid)

If no meetings are scheduled for the current day, a friendly message is displayed instead.

### Settings

Navigate to **Settings → Trumpet** to configure:

- **Preserve data on uninstall** — when checked, announcement posts and data are kept if the plugin is deleted. When unchecked, all announcement data is permanently removed on uninstall.

---

## Architecture

Trumpet follows a service-oriented architecture, registering its services into Unity's existing container.

```
trumpet/
├── trumpet.php                                      # Plugin bootstrap & hooks
├── composer.json                                    # Dependencies & PSR-4 autoloading
├── build.php                                        # Cross-platform build/packaging script
├── assets/
│   └── docs/trumpet.html                            # Bundled HTML documentation
└── src/
    └── Trumpet/
        ├── Plugin.php                               # Service registration & initialization
        ├── Config/
        │   └── TrumpetConfig.php                    # Option keys & defaults
        ├── Common/
        │   ├── DependencyContainer.php              # Container helper
        │   ├── Functions.php                        # Utility helpers
        │   ├── CacheInterface.php                   # Cache contract
        │   └── WordPressCache.php                   # Transient-backed cache
        ├── Admin/
        │   ├── TrumpetAdmin.php                     # Announcement admin interface
        │   └── TrumpetSettings.php                  # Settings page & uninstall options
        ├── Announcement/
        │   ├── Announcement.php                     # Value object / model
        │   ├── AnnouncementManager.php              # Business logic & rendering
        │   ├── AnnouncementRepository.php           # Persistence layer
        │   ├── AnnouncementRepositoryInterface.php  # Repository contract
        │   ├── AnnouncementChangeTracker.php        # Audit change detection
        │   └── AnnouncementException.php            # Domain exception
        ├── FrontPage/
        │   └── FrontPageManager.php                 # [todays_meetings] shortcode
        ├── Meetings/
        │   ├── Meeting.php                          # Meeting model
        │   ├── MeetingInterface.php                 # Meeting contract
        │   ├── Contact.php                          # Contact value object
        │   ├── MeetingRepository.php                # Meeting data access
        │   ├── MeetingRepositoryInterface.php       # Repository contract
        │   ├── MeetingFactoryInterface.php          # Factory contract
        │   └── TsmlMeetingFactory.php               # TSML-backed factory
        └── Exception/
            └── AnnouncementException.php            # Domain exception
```

**Service dependency graph:**

- `AnnouncementRepository` → standalone (WordPress post queries)
- `AnnouncementManager` → AnnouncementRepository, Cache
- `AnnouncementChangeTracker` → AnnouncementRepository
- `TrumpetAdmin` → AnnouncementManager, AnnouncementRepository
- `FrontPageManager` → MeetingRepository
- `TrumpetSettings` → standalone (option management)

All services are registered as lazy singletons in Unity's container.

---

## Building for Production

The included `build.php` script packages the plugin into a distributable `.zip` archive, stripping development files.

```bash
# Production build
php build.php build:production

# Development build (includes tests)
php build.php build:dev

# Clean the build directory
php build.php clean
```

You can override the version number with `--version=X.X` and add `--clean` to wipe the build directory before packaging.

---

## License

MIT License (Modified) — Copyright © 2025 The Bleeding Deacons.

This software is provided under the standard MIT license with one additional restriction: the licensee may not sell the Software, alone or as part of an aggregate software distribution containing the Software.

See [LICENSE](./LICENSE) for the full text.
