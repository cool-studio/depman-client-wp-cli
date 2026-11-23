<?php

/*
 * Registers `wp depman` with WP-CLI.
 *
 * Composer autoloads this file on every request that loads WP-CLI's package
 * autoloader, so it must do nothing else -- and nothing at all outside WP-CLI,
 * where the class it registers with does not exist.
 */

if (!class_exists('WP_CLI')) {
    return;
}

WP_CLI::add_command('depman', Depman\WpCli\Command::class);
