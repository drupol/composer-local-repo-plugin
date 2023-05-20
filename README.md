# composer-local-repo-plugin

This plugin for [Composer][composer website] allows to create a local repository
of type "composer" ([see documentation][composer repository]) from an existing
package.

This is useful when you want to install a Composer package without network
access.

This project is heavily inspired by
[`fossar/composition-c4`][fossar/composition-c4] from [Jan Tojnar][Jan Tojnar].

## Requirements

- Composer 2

## Installation

Install the plugin globally once:

```
composer global require loophp/composer-local-repo-plugin
```

## Usage

Create a local composer repository, for an existing package:

1. Move into the package directory (*an option will be provided in the future to skip this step*)
2. Make sure the `composer.json` and `composer.lock` files are present.
3. Build the local repository: `composer build-local-repo /path/to/local/repository`
4. Disable download from `packagist.org` repository: `composer config repo.packagist false`
5. Add the new local `composer` repository to the `composer.json` file: `composer config repo.local '{"type": "composer", "url": "file:///path/to/local/reposity"}'`
6. At this point you can disable network, no network access is needed any more
7. Update the lock file: `composer update --lock --no-install`
8. Install the package: `composer install`

[composer website]: https://getcomposer.org/
[fossar/composition-c4]: https://github.com/fossar/composition-c4/
[Jan Tojnar]: https://github.com/jtojnar
[composer repository]: https://getcomposer.org/doc/05-repositories.md#composer
