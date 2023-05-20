# composer-local-repo-plugin

A plugin for [Composer][composer website].

## Requirements

- Composer 2
- PHP 

## Usage

Install the plugin globally once:

```
composer global require loophp/composer-local-repo-plugin
```

Then, for each project where you want to create a local composer repository, do:

```
composer build-local-repo /path/to/local/repository
composer config repo.packagist false
composer config repo.local '{"type": "composer", "url": "file:///path/to/local/reposity"}'
composer update --lock --no-install
composer install
```

This project is inspired by `fossar/composition-c4` at https://github.com/fossar/composition-c4/

[composer website]: https://getcomposer.org/
