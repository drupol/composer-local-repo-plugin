# Composer Local Repo Plugin

This plugin, built for [Composer][composer website], facilitates the creation of
a local `composer` type repository
([refer to documentation][composer repository]) from an existing package.

The repository type `composer` is identical to the format used by
[Packagist][composer website]. It uses a specific structure, featuring a
manifest file named `packages.json` and a unique directory structure. Each
version of the package within the repository has its own corresponding source.

Created with the goal of installing a PHP package in a repeatable manner, this
plugin eliminates the need for network access post repository creation.

The plugin is currently used within Nix. More information can be found in the
[corresponding PR][php builder pr], marking the project's inception.

This project has been heavily inspired of
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

1. Move into the package directory (_an option will be provided in the future to
   skip this step_)
2. Make sure the `composer.json` and `composer.lock` files are present.
3. Build the local repository:
   `composer build-local-repo /path/to/local/repository` This command will
   create a local `composer` repository in the `/path/to/local/repository`
   directory and also a manifest file `packages.json` in the same directory. You
   can use the `-r` or `-m` to create a repository or manifest file only. See
   the command help (`composer build-local-repo --help`) for more information.
4. Disable download from `packagist.org` repository:
   `composer config repo.packagist false`
5. Add the new local `composer` repository to the `composer.json` file:
   `composer config repo.local '{"type": "composer", "url": "file:///path/to/local/reposity/packages.json"}'`
6. At this point you can delete the `vendor` directory and disable the network,
   no network access is needed any more
7. Update the lock file:
   `composer update --lock --no-install --no-scripts --no-plugins --no-interaction`
8. Install the package: `composer install`

### Note

By default, Composer will create symbolic links to the packages, if you want to
copy the packages instead, set the environment variable
`COMPOSER_MIRROR_PATH_REPOS` to 1

[composer website]: https://getcomposer.org/
[fossar/composition-c4]: https://github.com/fossar/composition-c4/
[Jan Tojnar]: https://github.com/jtojnar
[composer repository]: https://getcomposer.org/doc/05-repositories.md#composer
[php builder pr]: https://github.com/NixOS/nixpkgs/pull/225401
