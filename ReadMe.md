# One Time Registration WordPress Plugin

A WordPress Plugin to generate one-time links for registration of your WordPress site, and disallow any other type of registration.

## Installation

### Manually

Export this repository as a zip and install manually.

### Composer

At the moment I'm not going to bother with versions and such for this.

To install  or add the following to the `repositories` section of your composer.json file:

```
    {
      "type": "package",
      "package": {
        "name": "broskees/one-time-registration",
        "version": "master",
        "type": "wordpress-plugin",
        "source": {
          "url": "https://github.com/broskees/one-time-registration.git",
          "type": "git",
          "reference": "master"
        }
      }
    },
```

Then run `composer require broskees/one-time-registration`

## Updating with composer

Firstly remove the old version:

`composer remove broskees/one-time-registration`

Clear composer cache:

`composer clearcache`

Reinstall:

`composer require --prefer-dist broskees/one-time-registration:dev-master`
