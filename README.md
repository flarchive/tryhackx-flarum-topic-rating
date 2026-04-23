# Topic Rating for Flarum

A [Flarum](https://flarum.org/) extension that adds a 5-star rating system to discussions with half-star precision.

> **Latest (v1.0.3):** Support button moved to top of admin page with CSS improvements.

## Features

- **Half-star precision** - Users can rate from 0.5 to 5.0 stars (stored internally as 1-10)
- **Real-time updates** - Rating averages update live via polling
- **Ratings modal** - Click the rating count to see all individual ratings with user info
- **Moderator controls** - Toggle ratings on/off per discussion, reset all ratings
- **Permission-based** - Configurable permissions for rating, toggling, and resetting
- **Unactivated accounts** - Optional setting to allow unactivated users to rate
- **Localization** - Includes English and Polish translations

## Installation

Install with Composer:

```bash
composer require tryhackx/flarum-topic-rating
php flarum migrate
php flarum cache:clear
```

## Updating

```bash
composer update tryhackx/flarum-topic-rating
php flarum migrate
php flarum cache:clear
```

## Configuration

After installation, enable the extension in **Admin Panel > Extensions > Topic Rating**.

### Settings

- **Enable Topic Rating** - Global toggle for the rating system
- **Allow unactivated accounts** - Let users who haven't confirmed their email rate discussions

### Permissions

Set in **Admin Panel > Permissions**:

| Permission | Section | Default |
|---|---|---|
| Rate discussions | Reply | Members |
| Enable/Disable rating | Moderate | Moderators |
| Reset all ratings | Moderate | Moderators |

## Support Development

If you find this extension useful, consider supporting its development:

- **Monero (XMR):** `45hvee4Jv7qeAm6SrBzXb9YVjb8DkHtFtFh7qkDMxS9zYX3NRi1dV27MtSdVC5X8T1YVoiG8XFiJkh4p9UncqWGxHi4tiwk`
- **Bitcoin (BTC):** `bc1qncavcek4kknpvykedxas8kxash9kdng990qed2`
- **Ethereum (ETH):** `0xa3d38d5Cf202598dd782C611e9F43f342C967cF5`

You can also find the donation option in the extension's admin settings panel.

## Compatibility

- Flarum `>=1.8.0 <3.0.0`

## Links

- [GitHub](https://github.com/TryHackX/flarum-topic-rating)
- [Packagist](https://packagist.org/packages/tryhackx/flarum-topic-rating)

## License

MIT
