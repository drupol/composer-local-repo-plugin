# Composer Local Repo Plugin

This plugin, built for [Composer][composer website], facilitates the creation of
a local `composer` type repository ([refer to
documentation][composer repository]) from an existing package.

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

1. Navigate to the package directory, or use the `--working-dir` option.
2. Make sure the `composer.json` and `composer.lock` files are present.
3. Generate the local repository using the command:
   `composer build-local-repo /path/to/local/repository`. This will create a
   local `composer` repository in the specified directory and generate a
   manifest file `packages.json` in the same location. Use the `-r` or `-m`
   options to generate only a repository or a manifest file, respectively. For
   more details, refer to the command help: `composer build-local-repo --help`.
4. The combination of the package sources and the local repository we just
   created are now strictly enough to build the `vendor` directory necessary for
   the package to work. Therefore at this stage, we can disable the network as
   no further network access is required or you can use the environment variable
   `COMPOSER_DISABLE_NETWORK` set to `1` to do so.
5. Disable Packagist by entering the command:
   `composer config repo.packagist false`.
6. Integrate the newly created local `composer` repository into the
   `composer.json` file using the command:
   `composer config repo.local composer file:///path/to/local/reposity/packages.json`.
7. Refresh the lock file using the command:
   `composer update --lock --no-install --no-scripts --no-plugins --no-interaction`.
8. Finally, install the package by entering the command: `composer install`.

## In Nix

The plugin is used in Nix to build Composer based PHP packages. The combination
of the source package and the local repository built with this plugin is unique
and Nix uses that for ensuring reproducibility.

### Note

By default, Composer will create symbolic links to the packages, if you want to
copy the packages instead, set the environment variable
`COMPOSER_MIRROR_PATH_REPOS` to 1

[composer website]: https://getcomposer.org/
[fossar/composition-c4]: https://github.com/fossar/composition-c4/
[Jan Tojnar]: https://github.com/jtojnar
[composer repository]: https://getcomposer.org/doc/05-repositories.md#composer
[php builder pr]: https://github.com/NixOS/nixpkgs/pull/225401
